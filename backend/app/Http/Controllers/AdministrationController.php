<?php

namespace App\Http\Controllers;

use App\Models\ProjectActivityLog;
use App\Models\ReportDocument;
use App\Models\ReportingPeriod;
use App\Services\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdministrationController extends Controller
{
    public function __invoke(Request $r, WorkspaceAccess $access)
    {
        abort_unless($access->administrator($r->user()), 403);
        $projects = $access->projects($r->user());
        $ids = (clone $projects)->select('id');
        $periods = ReportingPeriod::whereIn('project_id', (clone $ids));
        $pending = (clone $periods)->where('status', 'needs_review')->with('project')->latest('updated_at')->limit(20)->get()->map(fn ($p) => [
            'id' => $p->id, 'project_id' => $p->project_id, 'title' => $p->title, 'project_name' => $p->project->name,
        ]);
        $failures = ReportDocument::whereIn('project_id', (clone $ids))->where('extraction_status', 'failed')->latest()->limit(20)->get(['id', 'project_id', 'reporting_period_id', 'original_filename']);
        $failedGenerations = DB::table('report_generations as g')->join('reporting_periods as p','p.id','=','g.reporting_period_id')->whereIn('p.project_id',(clone $ids))->where('g.status','failed')->orderByDesc('g.created_at')->limit(10)->get(['g.id','p.project_id','g.reporting_period_id','g.stage']);
        $failedExports = DB::table('report_pdf_exports as e')->join('reporting_periods as p','p.id','=','e.reporting_period_id')->whereIn('p.project_id',(clone $ids))->where('e.status','failed')->orderByDesc('e.created_at')->limit(10)->get(['e.id','p.project_id','e.reporting_period_id','e.type']);
        $events = ProjectActivityLog::whereIn('project_id', (clone $ids))->with('actor:id,name')->latest()->orderByDesc('id')->limit(30)->get(['id', 'project_id', 'reporting_period_id', 'actor_id', 'action', 'created_at']);

        return response()->json(['data' => [
            'employees' => $access->users($r->user())->where('role', '!=', 'client_viewer')->count(),
            'active_employees' => $access->users($r->user())->where('role', '!=', 'client_viewer')->where('is_active', true)->count(),
            'client_users' => $access->users($r->user())->where('role', 'client_viewer')->count(),
            'projects' => (clone $projects)->count(), 'pending' => $pending, 'failures' => $failures, 'failed_generations' => $failedGenerations, 'failed_exports' => $failedExports, 'events' => $events,
            'email_delivery' => in_array(config('mail.default'), ['log', 'array']) ? 'Local only - external invitation delivery is not configured' : 'Configured delivery transport - verify delivery on the target server',
            'can_view_system_health' => $access->super($r->user()),
        ]]);
    }
}
