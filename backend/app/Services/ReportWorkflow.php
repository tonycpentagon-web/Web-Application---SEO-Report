<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\User;

class ReportWorkflow
{
    public const TRANSITIONS = [
        'draft' => ['needs_review', 'archived'], 'processing' => ['needs_review', 'failed'],
        'needs_review' => ['draft', 'approved', 'archived'], 'approved' => ['needs_review', 'published', 'archived'],
        'published' => ['archived'], 'failed' => ['draft', 'archived'], 'archived' => ['draft'],
    ];

    public function __construct(public WorkspaceAccess $access) {}

    public function allowed(User $u, Project $p, ReportingPeriod $report): array
    {
        if ($p->status === 'archived' || $p->client?->status === 'archived') {
            return [];
        }
        $choices = self::TRANSITIONS[$report->status] ?? [];
        if ($this->access->manageProject($u, $p)) {
            return $choices;
        }

        return $this->access->workReports($u, $p) && $report->status === 'draft' ? ['needs_review'] : [];
    }
}
