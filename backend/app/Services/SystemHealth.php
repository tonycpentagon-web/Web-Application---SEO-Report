<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class SystemHealth
{
    public function probe(): array
    {
        return Cache::lock('operations:runtime-probe-lock', 70)->block(1, function () {
            $python = app(PythonDocumentProcessor::class)->doctor();
            $pythonReady = ($python['available'] ?? false) && ! in_array(false, $python['formats'] ?? [], true) && ($python['ocr']['available'] ?? false);
            $pdfReady = false;
            try {
                $p = new Process([config('delivery.node'), base_path('pdf-renderer/health.mjs'), config('delivery.chromium') ?? ''], base_path(), ProcessEnvironment::local());
                $p->setTimeout(35);
                $p->run();
                $pdfReady = $p->isSuccessful() && (json_decode($p->getOutput(), true)['ready'] ?? false);
            } catch (\Throwable) {
            }
            $result = ['checked_at' => now()->toIso8601String(), 'python' => ['status' => $pythonReady ? 'healthy' : (($python['available'] ?? false) ? 'warning' : 'unavailable'), 'message' => $pythonReady ? 'All extraction formats and an OCR engine responded.' : 'One or more extraction/OCR capabilities failed their local probe.'], 'pdf' => ['status' => $pdfReady ? 'healthy' : 'unavailable', 'message' => $pdfReady ? 'A real offline A4 PDF rendered successfully.' : 'Node/Chromium failed the offline PDF probe.']];
            Cache::put('operations:runtime-probes', $result, now()->addMinutes(15));

            return $result;
        });
    }

    public function snapshot(bool $http = false): array
    {
        $checks = ['api' => ['status' => $http ? 'healthy' : 'not_verified', 'message' => $http ? 'Authenticated Laravel request completed.' : 'CLI bootstrap succeeded; HTTP reachability was not tested by this command.']];
        $dbReady = false;
        try {
            DB::select('SELECT 1');
            $dbReady = true;
            $checks['database'] = ['status' => 'healthy', 'message' => 'Database query succeeded. Driver: '.DB::connection()->getDriverName().'.'];
        } catch (\Throwable) {
            $checks['database'] = ['status' => 'unavailable', 'message' => 'Database connectivity check failed.'];
        }
        $probe = 'health-probes/'.Str::uuid().'.probe';
        try {
            $disk = Storage::disk('local');
            if (! $disk->put($probe, 'health') || $disk->get($probe) !== 'health' || ! $disk->delete($probe) || $disk->exists($probe)) {
                throw new \RuntimeException;
            }$checks['storage'] = ['status' => 'healthy', 'message' => 'Private storage write/read/delete probe succeeded.'];
        } catch (\Throwable) {
            $checks['storage'] = ['status' => 'unavailable', 'message' => 'Private storage probe failed.'];
        } finally {
            try {
                Storage::disk('local')->delete($probe);
            } catch (\Throwable) {
            }
        }
        $queues = [];
        $counts = [];
        if ($dbReady) {
            try {
                foreach ([config('documents.queue'), 'exports'] as $queue) {
                    $beats = DB::table('worker_heartbeats')->where('queue', $queue)->orderByDesc('last_seen_at')->get();
                    $live = $beats->first(fn ($b) => Carbon::parse($b->last_seen_at)->gt(now()->subSeconds(in_array($b->state, ['busy', 'document_busy']) ? (int) config('documents.timeout') + 120 : ($b->state === 'export_busy' ? 360 : 90))));
                    $queues[$queue] = ['status' => $live ? 'healthy' : ($beats->isEmpty() ? 'not_verified' : 'warning'), 'last_seen_at' => $live?->last_seen_at ?? $beats->first()?->last_seen_at, 'message' => $live ? ($live->state !== 'idle' ? 'Worker observed executing a job within its timeout window.' : 'Recent idle-loop heartbeat observed.') : ($beats->isEmpty() ? 'No worker heartbeat has been observed.' : 'Worker heartbeat is stale; inspect the process supervisor.')];
                }
                if (config('queue.default') === 'database') {
                    foreach ([config('documents.queue'), 'exports'] as $queue) {
                        $counts[$queue] = DB::table('jobs')->where('queue', $queue)->count();
                    }
                }
                $counts['failed_jobs'] = DB::table('failed_jobs')->count();
                $counts['stuck_documents'] = DB::table('document_extraction_runs')->where('status', 'processing')->where('heartbeat_at', '<', now()->subSeconds(config('documents.timeout') + 120))->count();
                $counts['stuck_exports'] = DB::table('report_pdf_exports')->where('status', 'rendering')->where('updated_at', '<', now()->subMinutes(30))->count();
                $counts['stuck_normalizations'] = DB::table('normalization_runs')->where('status', 'processing')->where('started_at', '<', now()->subMinutes(6))->count();
            } catch (\Throwable) {
                $queues = ['monitoring' => ['status' => 'unavailable', 'message' => 'Operational tables are missing or unavailable. Apply additive migrations.']];
            }
        } else {
            $queues = ['monitoring' => ['status' => 'unavailable', 'message' => 'Queue diagnostics unavailable while the database is unavailable.']];
        }
        try {
            $runtime = Cache::get('operations:runtime-probes');
        } catch (\Throwable) {
            $runtime = null;
        }
        foreach (['python', 'pdf'] as $service) {
            $configured = $service === 'python' ? config('documents.python') : config('delivery.node');
            $checks[$service] = ! $configured ? ['status' => 'not_configured', 'message' => 'Required executable is not configured.'] : ($runtime[$service] ?? ['status' => 'not_verified', 'message' => 'Run the local runtime probes to test this service.']);
        }
        try {
            $scheduledAt = Cache::get('operations:scheduler-last-seen');
            $checks['scheduler'] = ['status' => ! $scheduledAt ? 'not_verified' : (Carbon::parse($scheduledAt)->gt(now()->subMinutes(3)) ? 'healthy' : 'warning'), 'message' => ! $scheduledAt ? 'No scheduled heartbeat observed.' : 'Scheduled heartbeat observed; verify individual scheduled tasks separately.', 'last_seen_at' => $scheduledAt];
        } catch (\Throwable) {
            $checks['scheduler'] = ['status' => 'unavailable', 'message' => 'Scheduler heartbeat storage unavailable.'];
        }
        $checks['production_configuration'] = ['status' => app(ProductionConfiguration::class)->errors(true) ? 'warning' : 'healthy', 'message' => app(ProductionConfiguration::class)->errors(true) ? 'Production preflight requirements remain incomplete.' : 'Static production configuration checks passed; deployment verification is separate.'];

        return ['checked_at' => now()->toIso8601String(), 'runtime_checked_at' => $runtime['checked_at'] ?? null, 'checks' => $checks, 'queues' => $queues, 'counts' => $counts, 'limits' => ['document_timeout_seconds' => (int) config('documents.timeout'), 'pdf_timeout_seconds' => 240], 'deployment' => 'not_verified'];
    }
}
