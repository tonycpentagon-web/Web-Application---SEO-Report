<?php

namespace App\Http\Controllers;

use App\Services\{DocumentAccess, SimplifiedReportSharing};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SimplifiedSharingController extends Controller
{
    public function show(Request $r, string $project, string $report, DocumentAccess $access, SimplifiedReportSharing $sharing)
    {
        $p = $access->report($r->user(), $project, $report);
        abort_unless($access->workspace->workReports($r->user(), $p->project), 403);
        $pubs = \App\Models\ReportPublication::where('reporting_period_id', $p->id)->orderByDesc('version')->get();
        return response()->json(['data' => ['version' => $p->version, 'status' => $p->status, 'report' => ['title' => $p->title, 'client_name' => $p->project->client?->name ?? $p->project->name, 'month' => $p->start_date->format('F Y')], 'assessment' => $sharing->assess($p),
            'links' => DB::table('report_share_links')->whereIn('publication_id', $pubs->pluck('id'))->where('access_mode', 'public')->orderByDesc('id')->get(['id', 'expires_at', 'revoked_at']),
            'exports' => \App\Models\ReportPdfExport::where('reporting_period_id', $p->id)->where('type', 'published')->latest()->get(['id', 'status', 'error'])]])->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $r, string $project, string $report, DocumentAccess $access, SimplifiedReportSharing $sharing)
    {
        $p = $access->report($r->user(), $project, $report);
        return response()->json(['data' => $sharing->preview($p, $r->user())])->header('Cache-Control', 'private, no-store');
    }

    public function sourceCoverage(Request $r, string $project, string $report, DocumentAccess $access, SimplifiedReportSharing $sharing)
    {
        $p=$access->report($r->user(),$project,$report);
        abort_unless($access->canUpload($r->user(),$p)||$access->canPreparePublication($r->user(),$p),403);
        $v=$r->validate(['version'=>'required|integer','fingerprint'=>'required|string|size:64','document_ids'=>'required|array|min:1|max:50','document_ids.*'=>'required|string|distinct','confirmed'=>'required|accepted']);
        return DB::transaction(function() use($p,$r,$v,$sharing){
            $p=\App\Models\ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            app(\App\Services\DraftInvalidation::class)->assertIdle([$p->id]);
            $a=$sharing->assess($p);abort_unless($p->version===$v['version']&&hash_equals($a['fingerprint'],$v['fingerprint']),409,'Report or source evidence changed. Reload.');
            abort_if($a['requires_regeneration']||$a['requires_validation_refresh'],409,'Generate and validate current source evidence before assigning coverage.');
            $input=app(\App\Services\EvidenceValidation::class)->input($p);
            foreach($v['document_ids'] as $document){
                $period=collect($a['period_mismatches'])->firstWhere('document_id',$document);abort_unless($period&&!empty($period['ranges']),422,'Select only the listed current cross-month sources.');
                abort_if(collect(app(\App\Services\EvidenceValidation::class)->current($p)['summary']['document_issues'])->contains(fn($i)=>($i['document_id']??null)===$document&&($i['code']??'')==='client_project_mismatch'),422,'Wrong-client source cannot be assigned.');
                abort_if(collect($period['ranges'])->contains(fn($range)=>$range['end']<$p->start_date->format('Y-m-d')||$range['start']>$p->end_date->format('Y-m-d')),422,'A wholly different reporting month remains a blocking mismatch.');
                $doc=$p->documents()->findOrFail($document);abort_unless(($input['files'][$document]??null)===$doc->checksum,422,'Original checksum must match.');
                $reason=['type'=>'explicit_actual_source_coverage','ranges'=>$period['ranges'],'selected_month'=>$p->reporting_month,'disclosure'=>'Employee explicitly selected these actual source dates for this reporting bundle. Values are not calendar-month totals. No missing days or OCR values are verified or estimated.'];
                DB::table('report_source_scope_verifications')->insert(['id'=>(string)\Illuminate\Support\Str::ulid(),'reporting_period_id'=>$p->id,'document_id'=>$document,'actor_id'=>$r->user()->id,'fingerprint'=>$input['scopes'][$document],'reason'=>json_encode($reason),'created_at'=>now()]);
            }
            app(\App\Services\EvidenceValidation::class)->evaluate($p,$r->user(),$p->status==='published');
            app(\App\Services\ReportDelivery::class)->event($p,null,$r->user(),'report.actual_source_coverage_selected',['documents'=>$v['document_ids'],'assessment'=>$a['fingerprint']]);
            return response()->json(['message'=>'Actual source coverage recorded. Printed dates and cross-month limitations remain visible; other material checks still apply.']);
        },5);
    }

    public function refresh(Request $r, string $project, string $report, DocumentAccess $access)
    {
        $p = $access->report($r->user(), $project, $report);
        abort_unless($access->workspace->workReports($r->user(), $p->project), 403);
        $v = $r->validate(['version' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);
        return DB::transaction(function () use ($p, $r, $v) {
            $p = \App\Models\ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless($p->version === $v['version'], 409, 'The report changed. Reload before refreshing.');
            app(\App\Services\DraftInvalidation::class)->assertIdle([$p->id]);
            $context = app(\App\Services\RecordReview::class)->context($p, true);
            abort_unless($context['current'] && !$p->requires_regeneration, 409, 'Sources changed. Regenerate Report using the existing uploads.');
            app(\App\Services\EvidenceValidation::class)->evaluate($p, $r->user(), $p->status==='published');
            app(\App\Services\ReportDelivery::class)->event($p, null, $r->user(), 'report.evidence_checks_refreshed', ['normalization_run_id' => $context['run']->id]);
            return response()->json(['message' => 'Evidence checks refreshed. The original source files and report values are unchanged.']);
        }, 5);
    }

    public function store(Request $r, string $project, string $report, DocumentAccess $access, SimplifiedReportSharing $sharing)
    {
        $p = $access->report($r->user(), $project, $report);
        $v = $r->validate(['version' => 'required|integer|min:1', 'fingerprint' => 'required|string|size:64', 'new_publication' => 'sometimes|boolean', 'acknowledge_uncertainty' => 'sometimes|boolean', 'confirm_source_periods' => 'sometimes|boolean', 'expires_at' => 'nullable|date|after:now|before:+1 year']);
        return response()->json(['data' => $sharing->share($p, $r->user(), $v)], 201)->header('Cache-Control', 'private, no-store');
    }
}
