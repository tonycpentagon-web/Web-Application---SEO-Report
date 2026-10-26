<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateMonthlyReport;
use App\Models\NormalizationRun;
use App\Models\ReportGeneration;
use App\Models\ReportingPeriod;
use App\Services\DocumentAccess;
use App\Services\ReportSourceAudit;
use App\Services\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MonthlyReportController extends Controller
{
    public function audit(Request $r, string $project, string $report, DocumentAccess $access)
    {
        $p = $access->report($r->user(), $project, $report);

        return response()->json(['data' => app(ReportSourceAudit::class)->build($p)])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $r, WorkspaceAccess $access)
    {
        abort_if($access->role($r->user()) === 'client_viewer', 403);

        $v=$r->validate(['tab'=>'sometimes|in:active,history,trash','page'=>'sometimes|integer|min:1']);
        $tab=$v['tab']??'active';
        abort_if($tab==='trash'&&!$access->administrator($r->user()),403,'Trash is managed by SEO Admin.');
        $q=ReportingPeriod::whereIn('project_id',$access->projects($r->user())->select('id'));
        $page=app(\App\Services\ReportWorkspace::class)->filter($q,$tab)->with('project.client')->orderByDesc('start_date')->orderBy('id')->paginate(24);
        $pubs=\App\Models\ReportPublication::whereIn('reporting_period_id',$page->pluck('id'))->orderByDesc('version')->get()->groupBy('reporting_period_id')->map->first();
        $links=DB::table('report_share_links')->whereIn('publication_id',$pubs->pluck('id'))->where('access_mode','public')->orderByDesc('created_at')->orderByDesc('id')->get()->groupBy('publication_id');
        $exports=\App\Models\ReportPdfExport::whereIn('publication_id',$pubs->pluck('id'))->where('type','published')->where('status','completed')->where(fn($q)=>$q->whereNull('profile')->orWhere('profile','client_preview'))->where('sections',json_encode(\App\Services\ReportDelivery::SECTIONS))->latest()->get()->groupBy('publication_id')->map->first();
        return $page->through(function($p)use($access,$r,$pubs,$links,$exports){
            $pub=$pubs->get($p->id);$available=$links->get($pub?->id,collect());
            $link=$available->first(fn($s)=>!$s->revoked_at&&now()->lt($s->expires_at))??$available->first();
            $active=$link&&!$link->revoked_at&&now()->lt($link->expires_at);
            return [...$p->toArray(),'can_manage_lifecycle'=>$access->manageProject($r->user(),$p->project)||($p->created_by===$r->user()->id&&$access->workReports($r->user(),$p->project)),'can_share'=>$access->workReports($r->user(),$p->project),'publication_id'=>$pub?->id,'published_at'=>$pub?->published_at,
                'pdf_export_id'=>$exports->get($pub?->id)?->id,'public_share'=>$link?['id'=>$link->id,'label'=>$link->label,'expires_at'=>$link->expires_at,'revoked_at'=>$link->revoked_at,'active'=>(bool)$active,'can_copy'=>$active&&(bool)$link->encrypted_token]:null,
                'purge_after'=>$p->deleted_at?->copy()->addDays(config('retention.trash_days'))];
        });
    }

    public function show(Request $r, string $project, string $report, DocumentAccess $access)
    {
        $p = $access->report($r->user(), $project, $report);
        $generation = ReportGeneration::where('reporting_period_id', $p->id)->latest()->orderByDesc('id')->first();
        if ($generation) {
            $generation->setAttribute('stale', in_array($generation->status, ['queued', 'processing']) && $generation->updated_at->lt(now()->subMinutes(35)));
        }
        $run = $generation?->normalization_run_id ? NormalizationRun::find($generation->normalization_run_id) : null;

        return response()->json(['data' => ['generation' => $generation, 'classifications' => $run?->summary['classifications'] ?? [], 'can_generate' => $access->canUpload($r->user(), $p), 'documents' => $p->documents()->with('currentRun')->get()->map(function ($d) use ($access, $r) {
            $attempt = $d->currentRun;
            $stale = $attempt && (($attempt->status === 'queued' && $attempt->created_at->lt(now()->subMinutes(5))) || ($attempt->status === 'processing' && ($attempt->heartbeat_at ?? $attempt->started_at)->lt(now()->subSeconds(config('documents.timeout') + 120))));

            return ['can_remove'=>$access->canManage($r->user(),$d), 'id' => $d->id, 'name' => $d->original_filename, 'status' => $d->extraction_status, 'stage' => $attempt?->stage, 'error' => $attempt?->error_message, 'stale' => (bool) $stale, 'can_retry' => $access->canManage($r->user(), $d) && ($stale || $d->extraction_status === 'failed')];
        })]]);
    }

    public function generate(Request $r, string $project, string $report, DocumentAccess $access)
    {
        $p = $access->report($r->user(), $project, $report);
        $v = $r->validate(['prepare_updated_publication' => 'sometimes|boolean', 'confirmed' => 'sometimes|accepted']);
        $update = $r->boolean('prepare_updated_publication');
        abort_if($update && !$r->boolean('confirmed'), 422, 'Explicit confirmation is required to prepare an updated publication.');
        abort_unless($update ? $access->canPreparePublication($r->user(), $p) : $access->canUpload($r->user(), $p), 403);
        $g = DB::transaction(function () use ($p, $r) {
            ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $active = ReportGeneration::where('reporting_period_id', $p->id)->whereIn('status', ['queued', 'processing'])->latest()->first();
            if ($active && $active->updated_at->gt(now()->subMinutes(35))) {
                return $active;
            }
            if ($active) {
                (new GenerateMonthlyReport($active->id))->failed(new \RuntimeException('Stale generation superseded by explicit retry.'));
            }
            abort_unless($p->documents()->exists(), 422, 'Upload the monthly source documents first.');

            return ReportGeneration::create(['reporting_period_id' => $p->id, 'requested_by' => $r->user()->id]);
        }, 5);
        if ($g->wasRecentlyCreated) {
            try {
                GenerateMonthlyReport::dispatch($g->id, $update);
            } catch (\Throwable $e) {
                (new GenerateMonthlyReport($g->id))->failed(new \RuntimeException('Unable to queue generation. Check the documents worker and retry.'));
            }
        }

        return response()->json(['data' => $g->fresh()], 202);
    }
}
