<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveProjectRequest;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Services\WorkspaceAccess;
use App\Services\WorkspaceView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    public function __construct(public WorkspaceAccess $access, public WorkspaceView $view) {}

    public function find(Request $r, string $id): Project
    {
        return $this->access->projects($r->user())->findOrFail($id);
    }

    public function index(Request $r)
    {
        $v = $r->validate(['search' => 'nullable|string|max:150', 'status' => 'nullable|in:active,archived', 'client_id' => 'nullable|ulid', 'page' => 'sometimes|integer|min:1', 'sort' => 'nullable|in:name,created_at,updated_at', 'direction' => 'nullable|in:asc,desc']);
        $q = $this->access->projects($r->user())->with('client');
        if ($s = $v['search'] ?? null) {
            $q->where(fn ($x) => $x->where('name', 'like', '%'.$s.'%')->orWhereHas('client', fn ($c) => $c->where('name', 'like', '%'.$s.'%'))->orWhere('client_name', 'like', '%'.$s.'%'));
        }
        if ($v['status'] ?? null) {
            $q->where('status', $v['status']);
        }
        if ($v['client_id'] ?? null) {
            $q->where('client_id', $v['client_id']);
        }

        return $q->orderBy($v['sort'] ?? 'created_at', $v['direction'] ?? 'desc')->orderBy('id')->paginate(12)->withQueryString()->through(fn ($p) => $this->view->project($r->user(), $p));
    }

    public function overview(Request $r)
    {
        abort_if($this->access->role($r->user()) === 'client_viewer', 403);
        $q = $this->access->projects($r->user());
        $reports = \App\Models\ReportingPeriod::whereIn('project_id', (clone $q)->select('id'));
        $workspace=app(\App\Services\ReportWorkspace::class);
        $active=$workspace->filter(clone $reports,'active');
        $shared=(clone $reports)->where('status','published')->whereIn('id',DB::table('report_publications as p')->join('report_share_links as s','s.publication_id','=','p.id')->whereNull('s.revoked_at')->where('s.expires_at','>',now())->where('s.created_at','>=',now()->subDays(30))->where(fn($q)=>$q->where('s.access_mode','public')->orWhereExists(fn($g)=>$g->selectRaw('1')->from('report_shares as g')->join('users as u','u.id','=','g.user_id')->whereColumn('g.reporting_period_id','p.reporting_period_id')->where('g.expires_at','>',now())->where('u.is_active',true)))->select('p.reporting_period_id'));
        $map=fn($p)=>['id'=>$p->id,'project_id'=>$p->project_id,'title'=>$p->title,'status'=>$p->status,'project_name'=>$p->project->name,'client_name'=>$p->project->client?->company_name?:$p->project->client?->name,'can_manage'=>$this->access->manageProject($r->user(),$p->project),'publication_id'=>$p->publication_id??null,'published_at'=>$p->published_at??null];
        $recent=(clone $active)->with('project.client')->orderByRaw("CASE WHEN status='needs_review' THEN 0 WHEN start_date>=? AND start_date<? THEN 1 ELSE 2 END",[now()->startOfMonth()->toDateString(),now()->startOfMonth()->addMonth()->toDateString()])->latest('updated_at')->orderByDesc('id')->limit(8)->get()->map($map);
        $recentPublished=(clone $reports)->where('status','published')->addSelect(['published_at'=>DB::table('report_publications')->select('published_at')->whereColumn('reporting_period_id','reporting_periods.id')->orderByDesc('version')->limit(1),'publication_id'=>DB::table('report_publications')->select('id')->whereColumn('reporting_period_id','reporting_periods.id')->orderByDesc('version')->limit(1)])->with('project.client')->orderByDesc('published_at')->orderByDesc('id')->limit(4)->get()->map($map);
        return response()->json([
            'total_clients' => $this->access->clients($r->user())->count(),
            'generated_this_month' => \App\Models\ReportGeneration::whereIn('reporting_period_id',(clone $reports)->select('id'))->where('status','completed')->where('completed_at','>=',now()->startOfMonth())->where('completed_at','<',now()->startOfMonth()->addMonth())->distinct()->count('reporting_period_id'),
            'recent_activity' => ProjectActivityLog::whereIn('project_id',(clone $q)->select('id'))->latest()->limit(8)->get(['id','action','created_at']),
            'total_projects' => (clone $q)->count(), 'active_projects' => (clone $q)->where('status', 'active')->count(), 'archived_projects' => (clone $q)->where('status', 'archived')->count(),
            'active_clients' => $this->access->clients($r->user())->where('status', 'active')->count(),
            'reports_this_month' => (clone $reports)->where('start_date', '>=', now()->startOfMonth()->toDateString())->where('start_date', '<', now()->startOfMonth()->addMonth()->toDateString())->count(),
            'draft_reports' => (clone $reports)->where('status', 'draft')->count(),
            'pending_manager_review' => (clone $reports)->where('status', 'needs_review')->count(),
            'published_reports' => (clone $reports)->where('status', 'published')->count(),
            'recently_shared_reports' => $shared->count(), 'recent_reports' => $recent, 'recent_published_reports'=>$recentPublished, 'active_reports'=>(clone $active)->count(),
            'recent_projects' => (clone $q)->where('status','active')->with('client')->latest()->orderBy('id')->limit(5)->get()->map(fn ($p) => $this->view->project($r->user(), $p)),
            'reporting_status' => 'periods_enabled',
        ]);
    }

    public function store(SaveProjectRequest $r)
    {
        abort_unless($this->access->administrator($r->user()), 403);
        $p = DB::transaction(function () use ($r) {
            $v = $r->validated();
            if (! empty($v['client_id'])) {
                $c = $this->access->clients($r->user())->findOrFail($v['client_id']);
                abort_unless($this->access->manageClient($r->user(), $c), 403);
            } else { // Backward-compatible Stage 1 request shape, scoped to the creating administrator.
                $name = trim($v['client_name']);
                $key = hash('sha256', mb_strtolower($name));
                $c = Client::where('user_id', $r->user()->id)->where('identity_key', $key)->first();
                if (! $c) {
                    $c = new Client;
                    $c->forceFill(['user_id' => $r->user()->id, 'identity_key' => $key, 'name' => $name, 'website' => $v['website']])->save();
                }
            }
            abort_if($c->status === 'archived', 409, 'Restore this client before creating a project.');
            unset($v['client_id']);
            $v['client_name'] = $c->name;
            $p = new Project($v);
            $p->user_id = $r->user()->id;
            $p->client_id = $c->id;
            $p->unsetRelation('client');
            $p->save();
            $this->activity($p, $r, 'project.created');

            return $p;
        });

        return response()->json(['data' => $this->view->project($r->user(), $p, true), 'message' => 'Project created successfully.'], 201);
    }

    public function show(Request $r, string $project)
    {
        return response()->json(['data' => $this->view->project($r->user(), $this->find($r, $project), true)]);
    }

    public function update(SaveProjectRequest $r, string $project)
    {
        $p = $this->find($r, $project);
        abort_unless($this->access->manageProject($r->user(), $p), 403);
        DB::transaction(function () use ($p, $r) {
            $v = $r->validated();
            Project::whereKey($p->id)->lockForUpdate()->firstOrFail();
            if(($v['status']??$p->status)!==$p->status)app(\App\Services\DraftInvalidation::class)->assertIdle(\App\Models\ReportingPeriod::withoutGlobalScopes()->where('project_id',$p->id)->pluck('id')->all());
            $before = $p->only(['name', 'client_id', 'website', 'description', 'status', 'reporting_frequency', 'start_date']);
            if (isset($v['client_id']) && $v['client_id'] !== $p->client_id) {
                $c = $this->access->clients($r->user())->findOrFail($v['client_id']);
                abort_unless($this->access->manageClient($r->user(), $c), 403);
                $p->client_id = $c->id;
                $p->unsetRelation('client');
            }
            unset($v['client_id']);
            $v['client_name'] = $p->client?->name ?? $p->client_name;
            $p->fill($v)->save();
            $this->activity($p, $r, 'project.updated', ['before' => $before, 'after' => $p->only(array_keys($before))]);
        });

        return response()->json(['data' => $this->view->project($r->user(), $p->fresh(), true), 'message' => 'Project updated successfully.']);
    }

    public function team(Request $r, string $project)
    {
        $p = $this->find($r, $project);
        abort_unless($this->access->manageProject($r->user(), $p), 403);
        $v = $r->validate(['manager_id' => 'nullable|integer', 'member_ids' => 'present|array|max:100', 'member_ids.*' => 'required|integer|distinct']);
        $members = $v['member_ids'];
        $manager = $v['manager_id'] ?? null;
        if ($manager && in_array($manager, $members)) {
            throw ValidationException::withMessages(['member_ids' => 'The manager cannot also be listed as an executive.']);
        }
        $ids = [...$members, ...($manager ? [$manager] : [])];
        $users = $this->access->assignable($r->user())->whereIn('id', $ids)->get()->keyBy('id');
        if ($users->count() !== count($ids)) {
            throw ValidationException::withMessages(['member_ids' => 'Select active team members from your permitted organization.']);
        }
        if ($manager && $users[$manager]->role !== 'seo_manager') {
            throw ValidationException::withMessages(['manager_id' => 'Select an SEO Manager.']);
        }
        foreach ($members as $id) {
            if ($users[$id]->role !== 'seo_executive') {
                throw ValidationException::withMessages(['member_ids' => 'Project members must be SEO Executives.']);
            }
        }
        $sync = [];
        foreach ($members as $id) {
            $sync[$id] = ['assignment_role' => 'member'];
        }if ($manager) {
            $sync[$manager] = ['assignment_role' => 'manager'];
        }
        DB::transaction(function () use ($p, $sync, $r) {
            $p->members()->sync($sync);
            $this->activity($p, $r, 'project.team_updated', ['user_ids' => array_keys($sync)]);
        });

        return response()->json(['data' => $this->view->project($r->user(), $p->fresh(), true), 'message' => 'Project team updated successfully.']);
    }

    public function activityIndex(Request $r, string $project)
    {
        $p = $this->find($r, $project);
        abort_if($this->access->role($r->user()) === 'client_viewer', 403);
        $r->validate(['page' => 'sometimes|integer|min:1']);

        return $p->activities()->with('actor:id,name')->latest()->orderByDesc('id')->paginate(20);
    }

    public function dashboard(Request $r, string $project)
    {
        $p = $this->find($r, $project);

        return response()->json(['project' => $this->view->project($r->user(), $p, true), 'reporting_status' => 'not_enabled', 'sections' => collect(['conversions' => 'Conversion events', 'traffic' => 'Traffic & search analytics', 'visibility' => 'AI search visibility', 'keywords' => 'Keyword ranking', 'backlinks' => 'Off-page backlink activity'])->map(fn ($name, $id) => ['id' => $id, 'name' => $name, 'status' => 'awaiting_report'])->values()]);
    }

    private function activity(Project $p, Request $r, string $action, array $properties = []): void
    {
        ProjectActivityLog::create(['project_id' => $p->id, 'actor_id' => $r->user()->id, 'action' => $action, 'properties' => $properties]);
    }
}
