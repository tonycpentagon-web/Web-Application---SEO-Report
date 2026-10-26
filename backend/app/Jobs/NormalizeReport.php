<?php

namespace App\Jobs;

use App\Models\NormalizationRun;
use App\Models\ReportingPeriod;
use App\Models\User;
use App\Services\DraftCommentary;
use App\Services\ReportNormalization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class NormalizeReport implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 240;

    public $failOnTimeout = true;

    public function __construct(public string $runId)
    {
        $this->onQueue('documents');
    }

    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\ClientDatasetGuard];
    }

    public function handle(ReportNormalization $engine): void
    {
        $engine->execute($this->runId);
        $run = NormalizationRun::findOrFail($this->runId);
        if ($run->status === 'completed') {
            app(DraftCommentary::class)->generate(ReportingPeriod::findOrFail($run->reporting_period_id), User::findOrFail($run->created_by));
        }
    }

    public function failed(?\Throwable $e): void
    {
        app(ReportNormalization::class)->fail($this->runId, $e);
    }
}
