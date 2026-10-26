<?php

namespace App\Console\Commands;

use App\Models\ReportPdfExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupReportExports extends Command
{
    protected $signature = 'reports:cleanup-exports {--dry-run}';

    protected $description = 'Expire old Draft artifacts; preserve all publication artifacts and export history';

    public function handle(): int
    {
        $count = 0;
        foreach (ReportPdfExport::whereNull('publication_id')->where('type', 'draft')->whereIn('status', ['completed', 'failed'])->where('created_at', '<', now()->subDays(max(1, config('delivery.draft_retention_days'))))->cursor() as $e) {
            $count++;
            if ($this->option('dry-run')) {
                continue;
            }
            if ($e->storage_path) {
                Storage::disk('local')->delete($e->storage_path);
            }
            Storage::disk('local')->delete(['exports/'.$e->id.'/input.html', 'exports/'.$e->id.'/report.pdf']);
            $e->update(['status' => 'expired', 'storage_path' => null, 'payload' => [], 'error' => null]);
        }
        // A killed worker cannot execute failed(); surface abandoned jobs for explicit retry.
        if (! $this->option('dry-run')) {
            ReportPdfExport::where('status', 'rendering')->where('updated_at', '<', now()->subMinutes(30))->update(['status' => 'failed', 'error' => 'Worker stopped before completion. Retry this export.']);
        }
        $this->info($count.' Draft exports eligible for cleanup. Published versions retained.');

        return self::SUCCESS;
    }
}
