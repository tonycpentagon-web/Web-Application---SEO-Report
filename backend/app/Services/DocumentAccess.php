<?php

namespace App\Services;

use App\Models\ReportDocument;
use App\Models\ReportingPeriod;
use App\Models\User;

class DocumentAccess
{
    public function __construct(public WorkspaceAccess $workspace) {}

    public function report(User $u, string $project, string $report): ReportingPeriod
    {
        $p = $this->workspace->projects($u)->findOrFail($project);
        $r = $this->workspace->reports($u, $p)->findOrFail($report);
        abort_if($this->workspace->role($u) === 'client_viewer', 403, 'Source documents and extraction evidence are available to the internal SEO team only.');

        return $r->setRelation('project', $p);
    }

    public function document(User $u, string $id): ReportDocument
    {
        $d = ReportDocument::findOrFail($id);
        $this->report($u, $d->project_id, $d->reporting_period_id);

        return $d;
    }

    public function canUpload(User $u, ReportingPeriod $r): bool
    {
        return $this->workspace->role($u) !== 'client_viewer' && $r->status === 'draft' && $r->project->status === 'active' && $r->project->client?->status !== 'archived' && $this->workspace->workReports($u, $r->project);
    }

    public function canPreparePublication(User $u, ReportingPeriod $r): bool
    {
        return $u->is_active && $r->status === 'published' && $r->project->status === 'active' && $r->project->client?->status !== 'archived' && $this->workspace->workReports($u, $r->project)
            && (int)(\App\Models\ReportPublication::where('reporting_period_id', $r->id)->orderByDesc('version')->first()?->snapshot['schema'] ?? 1) >= 2;
    }

    public function canManage(User $u, ReportDocument $d): bool
    {
        return $this->canUpload($u, $d->report) && ($d->uploaded_by === $u->id || $this->workspace->manageProject($u, $d->project));
    }
}
