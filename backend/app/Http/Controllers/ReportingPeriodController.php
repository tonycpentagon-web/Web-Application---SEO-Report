<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveReportingPeriodRequest;
use App\Models\NormalizedRecord;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\ReportingPeriod;
use App\Services\ReportDashboard;
use App\Services\ReportWorkflow;
use App\Services\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportingPeriodController extends Controller
{
    public function __construct(public WorkspaceAccess $access, public ReportWorkflow $workflow, public ReportDashboard $dashboard) {}

    private function project(Request $r, string $id): Project
    {
        return $this->access->projects($r->user())->findOrFail($id);
    }

    private function find(Request $r, Project $p, string $id): ReportingPeriod
    {
        return $this->access->reports($r->user(), $p)->findOrFail($id);
    }

    private function editable(Project $p): void
    {
        abort_if($p->status === 'archived' || $p->client?->status === 'archived', 409, 'Restore the client and project before changing reports.');
    }

    private function data(Request $r, Project $p, ReportingPeriod $v): array
    {
        $data = $v->toArray();
        if ($this->access->role($r->user()) === 'client_viewer') {
            unset($data['created_by'],$data['updated_by']);
        }
        $data['allowed_transitions'] = $this->workflow->allowed($r->user(), $p, $v);
        $data['can_edit'] = $this->access->workReports($r->user(), $p) && $v->status === 'draft' && $p->status === 'active' && $p->client?->status !== 'archived';
        $data['can_manage_lifecycle'] = $this->access->manageProject($r->user(), $p) || ($v->created_by === $r->user()->id && $this->access->workReports($r->user(), $p));
        $data['can_share'] = $this->access->workReports($r->user(), $p);
        if ($data['can_share']) {
            $data['shared_user_ids'] = $v->viewers()->pluck('users.id');
        }
        $data['can_upload_documents'] = $data['can_edit'];
        $data['view_documents'] = $this->access->role($r->user()) !== 'client_viewer';
        $data['documents_count'] = $data['view_documents'] ? $v->documents()->count() : 0;
        $data['has_data'] = NormalizedRecord::where('reporting_period_id', $v->id)->where('active', true)->where('kind', '!=', 'unclassified')->exists();
        if ($data['view_documents']) {
            $data['publication'] = $this->dashboard->build($v->setRelation('project', $p), $r->user(), false)['publication'];
            if (! $data['publication']['allowed']) {
                $data['allowed_transitions'] = array_values(array_diff($data['allowed_transitions'], ['published']));
            }
        }

        return $data;
    }

    public function index(Request $r, string $project)
    {
        $p = $this->project($r, $project);
        $v = $r->validate(['page' => 'sometimes|integer|min:1', 'status' => 'nullable|in:draft,processing,needs_review,approved,published,failed,archived']);
        $q = $this->access->reports($r->user(), $p);
        if ($v['status'] ?? null) {
            $q->where('status', $v['status']);
        }

        return $q->orderByDesc('start_date')->orderBy('id')->paginate(12)->through(fn ($v) => $this->data($r, $p, $v));
    }

    public function store(SaveReportingPeriodRequest $r, string $project)
    {
        $p = $this->project($r, $project);
        abort_unless($this->access->workReports($r->user(), $p), 403);
        $this->editable($p);
        $report = DB::transaction(function () use ($r, $p) {
            Project::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $v = $r->validated();
            unset($v['version']);
            $this->unique($p, $v);
            $report = $p->reports()->create([...$v, 'status' => 'draft', 'created_by' => $r->user()->id, 'updated_by' => $r->user()->id])->refresh();
            $this->log($r, $p, $report, 'report.created', ['after' => $report->toArray()]);

            return $report;
        });

        return response()->json(['data' => $this->data($r, $p, $report), 'message' => 'Reporting period created successfully.'], 201);
    }

    public function show(Request $r, string $project, string $report)
    {
        $p = $this->project($r, $project);
        $report = $this->find($r, $p, $report);
        $data = $this->data($r, $p, $report);
        $data['history'] = $this->access->role($r->user()) === 'client_viewer' ? [] : $p->activities()->where('reporting_period_id', $report->id)->with('actor:id,name')->oldest()->orderBy('id')->get();

        return response()->json(['data' => $data]);
    }

    public function update(SaveReportingPeriodRequest $r, string $project, string $report)
    {
        $p = $this->project($r, $project);
        $v = $this->find($r, $p, $report);
        abort_unless($this->access->workReports($r->user(), $p), 403);
        $this->editable($p);
        $r->validate(['version' => 'required|integer|min:1']);
        DB::transaction(function () use ($r, $p, &$v) {
            $v = ReportingPeriod::whereKey($v->id)->lockForUpdate()->firstOrFail();
            abort_unless($v->status === 'draft', 409, 'Return this report to draft before editing its dates or title.');
            abort_unless($v->version === $r->integer('version'), 409, 'This report changed. Reload it before saving.');
            $data = $r->validated();
            unset($data['version']);
            $this->unique($p, $data, $v->id);
            $before = $v->toArray();
            $v->fill([...$data, 'updated_by' => $r->user()->id, 'version' => $v->version + 1])->save();
            $this->log($r, $p, $v, 'report.updated', ['before' => $before, 'after' => $v->toArray()]);
        });

        return response()->json(['data' => $this->data($r, $p, $v), 'message' => 'Reporting period updated successfully.']);
    }

    public function status(Request $r, string $project, string $report)
    {
        if ($r->input('status') === 'published') {
            abort(409, 'Use Report Delivery to explicitly confirm publication and create an immutable snapshot.');
        }
        $p = $this->project($r, $project);
        $v = $this->find($r, $p, $report);
        abort_unless($this->access->workReports($r->user(), $p), 403);
        $this->editable($p);
        $data = $r->validate(['status' => 'required|in:draft,processing,needs_review,approved,published,failed,archived', 'version' => 'required|integer|min:1']);
        DB::transaction(function () use ($r, $p, &$v, $data) {
            $v = ReportingPeriod::whereKey($v->id)->lockForUpdate()->firstOrFail();
            abort_unless($v->version === $data['version'], 409, 'This report changed. Reload it before changing status.');
            abort_unless(in_array($data['status'], $this->workflow->allowed($r->user(), $p, $v)), 409, 'This status transition is not permitted.');
            if ($data['status'] === 'published') {
                $this->dashboard->assertPublishable($v->setRelation('project', $p), $r->user());
            }
            if($data['status']==='archived')app(\App\Services\DraftInvalidation::class)->assertIdle([$v->id]);
            $before = $v->status;
            $v->fill(['status' => $data['status'], 'updated_by' => $r->user()->id, 'version' => $v->version + 1])->save();
            $this->log($r, $p, $v, 'report.status_changed', ['before' => $before, 'after' => $v->status]);
        });

        return response()->json(['data' => $this->data($r, $p, $v), 'message' => 'Report status updated successfully.']);
    }

    public function shares(Request $r, string $project, string $report)
    {
        $p = $this->project($r, $project);
        $v = $this->find($r, $p, $report);
        abort_unless($this->access->manageProject($r->user(), $p), 403);
        $this->editable($p);
        $d = $r->validate(['user_ids' => 'present|array|max:100', 'user_ids.*' => 'integer|distinct', 'expires_at' => 'nullable|date|after:now|before:+1 year', 'allow_download' => 'sometimes|boolean']);
        $ids = $d['user_ids'];
        abort_unless($this->access->assignable($r->user())->where('role', 'client_viewer')->whereIn('id', $ids)->count() === count($ids), 422, 'Select active Client Viewers from your permitted organization.');
        $sync = [];
        foreach ($ids as $id) {
            $sync[$id] = ['shared_by' => $r->user()->id, 'expires_at' => $r->input('expires_at'), 'allow_download' => $r->boolean('allow_download')];
        }
        DB::transaction(function () use ($r, $p, $v, $sync, $ids) {
            $v = ReportingPeriod::whereKey($v->id)->lockForUpdate()->firstOrFail()->setRelation('project', $p);
            if ($ids) {
                $this->dashboard->assertPublishable($v, $r->user());
                abort_unless($v->status === 'published' && $r->filled('expires_at'), 409, 'Account sharing requires a Published report and an expiration date.');
                $publication = \App\Models\ReportPublication::where('reporting_period_id', $v->id)->latest('version')->first();
                abort_unless($publication, 409, 'An immutable publication is required before sharing.');
                app(\App\Services\ReportDelivery::class)->assertAvailable($publication);
            }
            $v->viewers()->sync($sync);
            $this->log($r, $p, $v, 'report.sharing_updated', ['user_ids' => $ids]);
        });

        return response()->json(['message' => 'Explicit report sharing updated successfully.']);
    }

    private function unique(Project $p, array $v, ?string $except = null): void
    {
        if ($p->reports()->withTrashed()->where('start_date', $v['start_date'])->where('end_date', $v['end_date'])->when($except, fn ($q) => $q->where('id', '!=', $except))->exists()) {
            throw ValidationException::withMessages(['start_date' => 'A report for this exact date range already exists, including archived reports and Trash. Restore the existing month rather than creating a duplicate.']);
        }
    }

    private function log(Request $r, Project $p, ReportingPeriod $v, string $action, array $properties): void
    {
        ProjectActivityLog::create(['project_id' => $p->id, 'reporting_period_id' => $v->id, 'actor_id' => $r->user()->id, 'action' => $action, 'properties' => $properties]);
    }
}
