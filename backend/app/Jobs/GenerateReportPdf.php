<?php

namespace App\Jobs;

use App\Models\ReportPdfExport;
use App\Services\ProcessEnvironment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

class GenerateReportPdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public string $exportId)
    {
        $this->onQueue('exports');
    }

    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\ClientDatasetGuard];
    }

    public function handle(): void
    {
        $e = ReportPdfExport::findOrFail($this->exportId);
        // Atomic claim: duplicate queue deliveries never overwrite an existing artifact.
        $claimed = DB::table('report_pdf_exports')->where('id', $e->id)->where('status', 'queued')->update(['status' => 'rendering', 'progress' => 20, 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $html = 'exports/'.$e->id.'/input.html';
        $pdf = 'exports/'.$e->id.'/report.pdf';
        try {
            $payload = $e->payload;
            $rendered = view('reports.pdf', ['d' => $payload, 'sections' => $e->sections, 'profile' => $e->profile ?? ($e->type === 'draft' ? 'internal_audit' : 'client_preview')])->render();
            if (! Storage::disk('local')->put($html, $rendered)) {
                throw new \RuntimeException('Private storage is not writable.');
            }
            $e->update(['progress' => 50]);
            $process = new Process([config('delivery.node'), base_path('pdf-renderer/render.mjs'), Storage::disk('local')->path($html), Storage::disk('local')->path($pdf), config('delivery.chromium') ?? ''], base_path(), ProcessEnvironment::local());
            $process->setTimeout(config('delivery.timeout'));
            $process->mustRun();
            if (! Storage::disk('local')->exists($pdf) || ! str_starts_with(Storage::disk('local')->get($pdf), '%PDF-')) {
                throw new \RuntimeException('No valid PDF was produced.');
            }
            $e->update(['status' => 'completed', 'progress' => 100, 'storage_path' => $pdf, 'file_size' => Storage::disk('local')->size($pdf), 'checksum' => hash_file('sha256', Storage::disk('local')->path($pdf)), 'generated_at' => now(), 'error' => null]);
        } catch (Throwable $error) {
            $this->failed($error);
            throw new \RuntimeException('PDF rendering failed. Check Chromium, Node, private storage and worker configuration.');
        } finally {
            Storage::disk('local')->delete($html);
        }
    }

    public function failed(?Throwable $error): void
    {
        ReportPdfExport::whereKey($this->exportId)->where('status', '!=', 'completed')->update(['status' => 'failed', 'error' => 'PDF generation failed or timed out. Check renderer dependencies, storage permissions and worker limits, then retry.', 'updated_at' => now()]);
    }
}
