<?php

namespace App\Http\Controllers;

use App\Models\NormalizedRecord;
use App\Models\ProjectActivityLog;
use App\Models\ReportingPeriod;
use App\Services\DocumentAccess;
use App\Services\DraftCommentary;
use App\Services\RecordReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportSummaryController extends Controller
{
    public const SECTIONS = ['website_traffic', 'organic_search', 'keywords', 'seo_work', 'ai_visibility', 'key_events'];

    public const ALL_SECTIONS = [...self::SECTIONS, 'data_limitations'];

    public function __construct(public DocumentAccess $access, public RecordReview $reviews) {}

    public function fingerprint(ReportingPeriod $p): string
    {
        $rows = NormalizedRecord::where('reporting_period_id', $p->id)->orderBy('id')->get(['id', 'normalization_run_id', 'source_hash', 'source_data', 'manual_override', 'review_status', 'revision', 'active']);
        $markers = DB::table('record_verifications')->whereIn('normalized_record_id', $rows->pluck('id'))->orderBy('id')->get(['id', 'context_hash', 'value_hash']);

        return hash('sha256', json_encode([$this->reviews->engine->fingerprint($p, $this->reviews->engine->snapshot($p)), $rows, $markers]));
    }

    private function payload(Request $r, ReportingPeriod $p): array
    {
        $latest = DB::table('report_summary_revisions')->where('reporting_period_id', $p->id)->orderByDesc('revision')->first();
        $fingerprint = $this->fingerprint($p);

        return ['revision' => $latest?->revision ?? 0, 'sections' => $latest ? json_decode($latest->sections, true) : array_fill_keys(self::ALL_SECTIONS, ''), 'origin' => $latest?->origin ?? 'manual',
            'approved' => $latest && $latest->source_fingerprint === $fingerprint && DB::table('report_summary_approvals')->where('summary_revision_id', $latest->id)->where('fingerprint', $fingerprint)->exists(),
            'source_fingerprint' => $fingerprint, 'stale' => $latest && $latest->source_fingerprint !== $fingerprint,
            'can_edit' => $this->access->canUpload($r->user(), $p), 'actor_id' => $latest?->actor_id, 'updated_at' => $latest?->created_at,
            'history' => DB::table('report_summary_revisions')->where('reporting_period_id', $p->id)->orderByDesc('revision')->get(['id', 'revision', 'actor_id', 'reason', 'created_at'])];
    }

    public function show(Request $r, string $project, string $report)
    {
        $p = $this->access->report($r->user(), $project, $report);

        return response()->json(['data' => $this->payload($r, $p)])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $r, string $project, string $report)
    {
        $p = $this->access->report($r->user(), $project, $report);
        abort_unless($this->access->canUpload($r->user(), $p), 403, 'Summary editing requires access to an active Draft report.');
        $rules = ['revision' => 'required|integer|min:0', 'source_fingerprint' => 'required|string|size:64', 'sections' => 'required|array:'.implode(',', self::ALL_SECTIONS), 'sections.data_limitations' => 'sometimes|nullable|string|max:3000', 'reason' => 'required|string|min:10|max:2000', 'confirmed' => 'required|accepted'];
        foreach (self::SECTIONS as $key) {
            $rules['sections.'.$key] = 'present|nullable|string|max:3000';
        }
        $v = $r->validate($rules);
        DB::transaction(function () use ($r, $p, $v) {
            $p = ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->access->canUpload($r->user(), $p), 409, 'The report workflow or permissions changed.');
            $this->reviews->context($p, true); // Serialize with source extraction updates.
            $latest = DB::table('report_summary_revisions')->where('reporting_period_id', $p->id)->orderByDesc('revision')->first();
            abort_unless(($latest?->revision ?? 0) === $v['revision'], 409, 'The summary changed. Reload it before saving.');
            abort_unless(hash_equals($this->fingerprint($p), $v['source_fingerprint']), 409, 'Reporting data changed. Reload the summary and check its source evidence before saving.');
            DB::table('report_summary_revisions')->insert(['id' => (string) Str::ulid(), 'reporting_period_id' => $p->id, 'project_id' => $p->project_id, 'actor_id' => $r->user()->id,
                'revision' => $v['revision'] + 1, 'source_fingerprint' => $v['source_fingerprint'], 'sections' => json_encode($v['sections']), 'reason' => $v['reason'], 'created_at' => now(), 'updated_at' => now()]);
            ProjectActivityLog::create(['project_id' => $p->project_id, 'reporting_period_id' => $p->id, 'actor_id' => $r->user()->id, 'action' => 'report.summary_edited', 'properties' => ['summary_revision' => $v['revision'] + 1, 'reason' => $v['reason']]]);
        }, 5);

        return response()->json(['data' => $this->payload($r, $p), 'message' => 'Summary commentary saved with revision history. Reporting values and review decisions are unchanged.']);
    }

    public function generate(Request $r, string $project, string $report)
    {
        $p = $this->access->report($r->user(), $project, $report);
        abort_unless($this->access->canUpload($r->user(), $p), 403);
        $v = $r->validate(['revision' => 'required|integer|min:0', 'source_fingerprint' => 'required|string|size:64', 'replace_manual' => 'sometimes|boolean', 'confirmed' => 'required|accepted']);
        $result = DB::transaction(function () use ($r, $p, $v) {
            $p = ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->access->canUpload($r->user(), $p), 409, 'The report workflow or permissions changed.');
            $latest = DB::table('report_summary_revisions')->where('reporting_period_id', $p->id)->orderByDesc('revision')->first();
            abort_unless(($latest?->revision ?? 0) === $v['revision'] && hash_equals($this->fingerprint($p), $v['source_fingerprint']), 409, 'Sources or commentary changed. Reload before generating.');

            return app(DraftCommentary::class)->generate($p, $r->user(), $r->boolean('replace_manual'));
        }, 5);

        return response()->json(['data' => $this->payload($r, $p), 'generation' => $result, 'message' => $result['preserved'] ? 'Existing commentary was preserved.' : 'Factual Draft commentary generated. Explicit approval is still required.']);
    }
}
