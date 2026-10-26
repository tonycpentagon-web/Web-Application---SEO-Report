<?php

namespace App\Jobs;

use App\Models\NormalizationRun;
use App\Models\ReportGeneration;
use App\Models\ReportingPeriod;
use App\Models\User;
use App\Services\DocumentAccess;
use App\Services\DocumentFailure;
use App\Services\DraftCommentary;
use App\Services\ReportNormalization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateMonthlyReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 240;

    public bool $failOnTimeout = true;

    public int $deadline;

    public function __construct(public string $generationId, public bool $prepareUpdatedPublication = false)
    {
        $this->onQueue('documents');
        $this->deadline = now()->addMinutes(30)->timestamp;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return (new \DateTimeImmutable)->setTimestamp($this->deadline);
    }

    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\ClientDatasetGuard];
    }

    public function handle(ReportNormalization $engine, DocumentAccess $access): void
    {
        $generation = ReportGeneration::findOrFail($this->generationId);
        if ($generation->status !== 'queued') {
            return;
        }
        try {
            $period = ReportingPeriod::findOrFail($generation->reporting_period_id);
            $user = User::findOrFail($generation->requested_by);
            abort_unless($user->is_active && ($this->prepareUpdatedPublication ? $access->canPreparePublication($user, $period) : $access->canUpload($user, $period)), 403, 'Report access changed.');
            $snapshot = $engine->snapshot($period);
            if (collect($snapshot)->contains(fn ($d) => in_array($d['status'], ['queued', 'processing']))) {
                $generation->update(['stage' => 'Waiting for source extraction; review document progress below']);
                $this->release(5);

                return;
            }
            if (! collect($snapshot)->contains(fn ($d) => in_array($d['status'], ['extracted', 'partially_extracted', 'needs_review']))) {
                throw new \RuntimeException('No readable source documents. Retry failed extraction before generating.');
            }
            $run = DB::transaction(function () use ($generation, $period, $snapshot, $engine, $access, $user) {
                $lockedPeriod = ReportingPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
                abort_unless(($this->prepareUpdatedPublication ? $access->canPreparePublication($user, $lockedPeriod) : $access->canUpload($user, $lockedPeriod)), 409, 'Report access or status changed.');
                abort_unless($engine->snapshot($lockedPeriod) === $snapshot, 409, 'Source documents changed. Retry generation after extraction finishes.');
                $locked = ReportGeneration::whereKey($generation->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'queued') {
                    return null;
                }
                abort_if(NormalizationRun::where('reporting_period_id', $period->id)->whereIn('status', ['queued', 'processing'])->exists(), 409, 'Another normalization is active. Wait or retry it in Data Review.');
                $run = NormalizationRun::create(['project_id' => $period->project_id, 'reporting_period_id' => $period->id, 'created_by' => $locked->requested_by, 'status' => 'queued', 'fingerprint' => $engine->fingerprint($period, $snapshot), 'input_snapshot' => $snapshot]);
                $locked->update(['status' => 'processing', 'stage' => 'Classifying sources and building dashboard observations', 'normalization_run_id' => $run->id]);

                return $run;
            }, 5);
            if (! $run) {
                return;
            }
            $engine->execute($run->id);
            $period = DB::transaction(function () use ($period, $run, $engine, $user) {
                $current = ReportingPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
                abort_unless($engine->fingerprint($current, $engine->snapshot($current)) === $run->fingerprint, 409, 'Sources changed during generation. Regenerate using the current uploads.');
                $current->forceFill(['version' => $current->version + 1, 'updated_by' => $user->id])->save();
                return $current;
            }, 5);
            if ($this->prepareUpdatedPublication) app(\App\Services\EvidenceValidation::class)->evaluate($period, $user, true);
            app(DraftCommentary::class)->generate($period, $user, false, $this->prepareUpdatedPublication);
            $generation->update(['status' => 'completed', 'stage' => 'Your SEO report is ready. Preview, optionally edit, then Share Report.', 'completed_at' => now()]);
        } catch (Throwable $e) {
            $this->failed($e);
            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        $generation = ReportGeneration::find($this->generationId);
        if ($generation && in_array($generation->status, ['queued', 'processing']) && $generation->normalization_run_id) {
            app(ReportNormalization::class)->fail($generation->normalization_run_id, new \RuntimeException('Monthly generation stopped. Retry generation after checking the worker and sources.'));
        }
        ReportGeneration::whereKey($this->generationId)->whereIn('status', ['queued', 'processing'])->update(['status' => 'failed', 'stage' => 'Generation stopped', 'error_message' => mb_substr(DocumentFailure::message($e), 0, 350), 'completed_at' => now(), 'updated_at' => now()]);
    }
}
