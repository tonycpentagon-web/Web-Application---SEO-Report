<?php

namespace App\Http\Controllers;

use App\Models\ReportingPeriod;
use App\Services\DocumentAccess;
use App\Services\DraftInvalidation;
use App\Services\EvidenceValidation;
use App\Services\ManagerReportApproval;
use App\Services\RecordReview;
use App\Services\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EvidenceValidationController extends Controller
{
    public function reevaluate(Request $r, string $project, string $report)
    {
        $p = app(DocumentAccess::class)->report($r->user(), $project, $report);
        abort_unless(app(WorkspaceAccess::class)->administrator($r->user()) && app(WorkspaceAccess::class)->workReports($r->user(), $p->project), 403, 'Only an authorized administrator may re-evaluate existing reports.');
        $v = $r->validate(['confirmed' => 'required|accepted', 'version' => 'required|integer|min:1']);

        return DB::transaction(function () use ($r, $p, $v) {
            $p = ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless($p->version === $v['version'], 409, 'Report changed. Reload before re-evaluation.');
            app(DraftInvalidation::class)->assertIdle([$p->id]);
            $out = app(EvidenceValidation::class)->evaluate($p, $r->user());

            return response()->json(['data' => $out, 'message' => 'Validation re-evaluated. Original values, human decisions, corrections and published snapshots are unchanged.']);
        }, 5);
    }

    public function approve(Request $r, string $project, string $report)
    {
        $p = app(DocumentAccess::class)->report($r->user(), $project, $report);
        $v = $r->validate(['confirmed' => 'required|accepted', 'version' => 'required|integer|min:1', 'fingerprint' => 'required|string|size:64', 'reason' => 'required|string|min:10|max:2000']);

        return response()->json(['data' => app(ManagerReportApproval::class)->approve($p, $r->user(), $v), 'message' => 'Report and commentary approved as a whole. Individual human-verification history is unchanged.']);
    }

    public function sourceScope(Request $r, string $project, string $report, string $document)
    {
        $p = app(DocumentAccess::class)->report($r->user(), $project, $report);
        abort_unless(app(WorkspaceAccess::class)->manageProject($r->user(), $p->project), 403);
        $v = $r->validate(['confirmed' => 'required|accepted', 'version' => 'required|integer|min:1', 'fingerprint' => 'required|string|size:64', 'reason' => 'required|string|min:20|max:2000']);

        return DB::transaction(function () use ($r, $p, $v, $document) {
            $p = ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless($p->status === 'draft' && $p->version === $v['version'], 409, 'Source scope confirmation requires the current Draft.');
            app(RecordReview::class)->context($p, true);
            $engine = app(EvidenceValidation::class);
            $input = $engine->input($p);
            $current = $engine->current($p, $input);
            abort_unless($current && $current['current'] && hash_equals($current['fingerprint'], $v['fingerprint']), 409, 'Reload current validation before confirming source scope.');
            $doc = $p->documents()->findOrFail($document);
            $issues = collect($current['summary']['document_issues'])->where('document_id', $doc->id);
            abort_if($issues->contains(fn ($i) => in_array($i['code'], ['date_range_mismatch', 'client_project_mismatch'])), 422, 'Explicit source date or client contradictions cannot be waived with scope confirmation.');
            abort_unless($issues->contains(fn ($i) => in_array($i['code'], ['period_not_reported', 'period_requires_inspection'])), 422, 'This source has no unresolved scope exception.');
            DB::table('report_source_scope_verifications')->insert(['id' => (string) Str::ulid(), 'reporting_period_id' => $p->id, 'document_id' => $doc->id, 'actor_id' => $r->user()->id, 'fingerprint' => $input['scopes'][$doc->id], 'reason' => $v['reason'], 'created_at' => now()]);
            $out = $engine->evaluate($p,$r->user());

            return response()->json(['data' => $out, 'message' => 'Source association explicitly confirmed. Printed dates and individual human-verification records are unchanged.']);
        }, 5);
    }
}
