<?php

namespace App\Http\Controllers;

use App\Jobs\NormalizeReport;
use App\Models\NormalizationRun;
use App\Models\NormalizedRecord;
use App\Models\ReportingPeriod;
use App\Services\DocumentAccess;
use App\Services\EvidenceValidation;
use App\Services\RecordReview;
use App\Services\ReportNormalization;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ReportDataController extends Controller
{
    public function __construct(public DocumentAccess $access, public ReportNormalization $engine, public RecordReview $reviews) {}

    private function period(Request $r, string $project, string $report): ReportingPeriod
    {
        return $this->access->report($r->user(), $project, $report);
    }

    private function canReview(Request $r, ReportingPeriod $p): bool
    {
        return $this->reviews->canReview($r->user(), $p);
    }

    private function current(ReportingPeriod $p)
    {
        return NormalizationRun::where('reporting_period_id', $p->id)->latest()->orderByDesc('id')->first();
    }

    public function summary(Request $r, string $project, string $report)
    {
        $p = $this->period($r, $project, $report);
        $run = $this->current($p);

        return response()->json(['data' => ['run' => $run, 'runs' => NormalizationRun::where('reporting_period_id', $p->id)->latest()->get(), 'sections' => DB::table('report_sections')->where('reporting_period_id', $p->id)->get(), 'counts' => NormalizedRecord::where('reporting_period_id', $p->id)->where('active', true)->select('kind', DB::raw('count(*) as count'))->groupBy('kind')->pluck('count', 'kind'), 'stale' => $p->requires_regeneration || ! $run || $run->status !== 'completed' || $run->fingerprint !== $this->engine->fingerprint($p, $this->engine->snapshot($p)), 'report_revision' => $p->version, 'can_reevaluate' => $this->access->workspace->administrator($r->user()) && $this->access->workspace->workReports($r->user(), $p->project) && in_array($p->status, ['draft', 'needs_review']), 'validation' => ($validation = app(EvidenceValidation::class)->current($p)) ? array_diff_key($validation, ['records' => true]) : null, 'can_normalize' => $this->access->canUpload($r->user(), $p), 'can_review' => $this->canReview($r, $p)]]);
    }

    public function normalize(Request $r, string $project, string $report)
    {
        $p = $this->period($r, $project, $report);
        abort_unless($this->access->canUpload($r->user(), $p), 403, 'Normalization requires an active project and Draft report.');
        $run = DB::transaction(function () use ($r, $p) {
            $locked = ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->access->canUpload($r->user(), $locked), 409);
            $active = NormalizationRun::where('reporting_period_id', $p->id)->whereIn('status', ['queued', 'processing'])->latest()->first();
            if ($active && $active->updated_at->gt(now()->subMinutes(10))) {
                abort(409, 'A normalization attempt is already queued or processing. Start the documents worker or wait.');
            }
            if ($active) {
                $active->update(['status' => 'failed', 'completed_at' => now(), 'error_message' => 'Stale normalization attempt superseded by an explicit rerun.']);
            }
            $snapshot = $this->engine->snapshot($p);
            abort_if(! $snapshot, 422, 'Upload and extract source documents first.');
            abort_if(collect($snapshot)->contains(fn ($d) => in_array($d['status'], ['queued', 'processing'])), 409, 'Wait for source extraction to finish before normalization.');

            return NormalizationRun::create(['project_id' => $p->project_id, 'reporting_period_id' => $p->id, 'created_by' => $r->user()->id, 'status' => 'queued', 'fingerprint' => $this->engine->fingerprint($p, $snapshot), 'input_snapshot' => $snapshot]);
        }, 5);
        try {
            NormalizeReport::dispatch($run->id);
        } catch (\Throwable $e) {
            $this->engine->fail($run->id, new \RuntimeException('Unable to queue normalization. Check the queue worker and retry.'));
        }

        return response()->json(['data' => $run->fresh(), 'message' => 'Normalization queued. Existing source files and review decisions are retained.'], 202);
    }

    private function query(ReportingPeriod $p)
    {
        return NormalizedRecord::where('reporting_period_id', $p->id)->where('project_id', $p->project_id)->where('active', true);
    }

    public function records(Request $r, string $project, string $report)
    {
        $p = $this->period($r, $project, $report);
        $v = $r->validate(['kind' => 'nullable|in:'.implode(',', ReportNormalization::KINDS), 'section' => 'nullable|string|max:40', 'status' => 'nullable|in:pending,approved,rejected', 'page' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:200', 'validation' => 'nullable|in:AUTO_VALIDATED,HUMAN_VERIFIED,NEEDS_REVIEW,INVALID,NOT_APPLICABLE,attention', 'eligibility' => 'nullable|in:eligible,ineligible,verified,unverified']);
        $q = $this->query($p)->with(['sources' => fn ($q) => $q->orderBy('id')]);
        if ($v['kind'] ?? null) {
            $q->where('kind', $v['kind']);
        }if ($v['section'] ?? null) {
            $q->where('section_key', $v['section']);
        }if ($v['status'] ?? null) {
            $q->where('review_status', $v['status']);
        }
        if ($v['search'] ?? null) {
            $q->whereRaw("source_data LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $v['search']).'%']);
        }

        $context = $this->reviews->context($p);
        $validation = app(EvidenceValidation::class)->current($p);
        if (empty($v['eligibility']) && empty($v['validation'])) {
            $page = $q->orderBy('kind')->orderBy('created_at')->orderBy('id')->paginate(25);
            foreach ($page->items() as $row) {
                $row->setAttribute('eligibility', $this->reviews->eligibility($row, $p, $r->user(), $context));
                $row->setAttribute('validation', $validation['records'][$row->id] ?? null);
            }

            return $page;
        }
        $rows = $q->orderBy('kind')->orderBy('created_at')->orderBy('id')->get();
        foreach ($rows as $row) {
            $row->setAttribute('eligibility', $this->reviews->eligibility($row, $p, $r->user(), $context));
            $row->setAttribute('validation', $validation['records'][$row->id] ?? null);
        }
        if ($v['eligibility'] ?? null) {
            $filter = $v['eligibility'];
            $rows = $rows->filter(fn ($row) => match ($filter) {
                'eligible' => $row->eligibility['eligible'], 'ineligible' => ! $row->eligibility['eligible'],
                'verified' => $row->eligibility['verified'], 'unverified' => ! $row->eligibility['verified'],
            })->values();
        }
        if ($filter = $v['validation'] ?? null) {
            $rows = $rows->filter(fn ($row) => $filter === 'attention' ? ($row->kind !== 'unclassified' && $row->review_status !== 'rejected' && in_array($row->validation['status'] ?? 'NEEDS_REVIEW', ['NEEDS_REVIEW', 'INVALID'])) : ($row->validation['status'] ?? 'NEEDS_REVIEW') === $filter)->values();
        }
        $page = (int) ($v['page'] ?? 1);

        return new LengthAwarePaginator($rows->forPage($page, 25)->values(), $rows->count(), 25, $page, ['path' => $r->url(), 'query' => $r->query()]);
    }

    public function show(Request $r, string $project, string $report, string $record)
    {
        $p = $this->period($r, $project, $report);
        $v = $this->query($p)->findOrFail($record);

        $v->load('sources', 'corrections');
        $validation = app(EvidenceValidation::class)->current($p);
        $v->setAttribute('validation', $validation['records'][$v->id] ?? null);
        $v->setAttribute('eligibility', $this->reviews->eligibility($v, $p, $r->user(), $this->reviews->context($p)));

        return response()->json(['data' => $v]);
    }

    public function warnings(Request $r, string $project, string $report)
    {
        $p = $this->period($r, $project, $report);
        $run = $this->current($p);

        return DB::table('validation_warnings')->where('normalization_run_id', $run?->id ?? 'none')->orderBy('created_at')->paginate(30);
    }

    public function review(Request $r, string $project, string $report, string $record)
    {
        $p = $this->period($r, $project, $report);
        abort_unless($this->canReview($r, $p), 403, 'A project manager or administrator must review normalized data.');
        $v = $r->validate(['review_status' => 'required|in:pending,approved,rejected', 'reason' => 'required|string|min:3|max:2000', 'revision' => 'required|integer|min:1', 'data' => 'sometimes|array', 'verify_source' => 'sometimes|accepted', 'resolutions' => 'sometimes|array|max:100', 'resolutions.*.warning_id' => 'required|ulid|distinct', 'resolutions.*.reason' => 'required|string|min:10|max:2000']);
        $out = DB::transaction(function () use ($r, $p, $record, $v) {
            $p = ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canReview($r, $p), 409);
            $latest = $this->current($p);
            abort_unless($latest && $latest->status === 'completed' && $latest->fingerprint === $this->engine->fingerprint($p, $this->engine->snapshot($p)), 409, 'Sources changed or normalization is pending. Re-run normalization before review.');
            $row = $this->query($p)->where('normalization_run_id', $latest->id)->lockForUpdate()->findOrFail($record);
            abort_unless($row->revision === $v['revision'], 409, 'This record changed. Reload before saving.');
            $before = $row->toArray();
            $data = array_key_exists('data', $v) ? $this->reviews->validateData($row, $v['data']) : $row->data;
            $override = [];
            foreach ($data as $key => $value) {
                if (! array_key_exists($key, $row->source_data) || $value !== $row->source_data[$key]) {
                    $override[$key] = $value;
                }
            }
            $row->manual_override = $override ?: null;
            $context = $this->reviews->context($p, true);
            if ($v['verify_source'] ?? false) {
                $this->reviews->verify($row, $p, $r->user(), $context, $v['reason'], $v['resolutions'] ?? []);
            }
            if ($v['review_status'] === 'approved') {
                $eligibility = $this->reviews->eligibility($row, $p, $r->user(), $context);
                $reasons = array_filter($eligibility['reasons'], fn ($reason) => $reason !== 'Already approved; the existing decision will be preserved.');
                abort_if((bool) $reasons, 422, implode(' ', $reasons));
            }
            $row->update(['manual_override' => $override ?: null, 'review_status' => $v['review_status'], 'reviewed_by' => $r->user()->id, 'reviewed_at' => now(), 'revision' => $row->revision + 1]);
            $this->reviews->audit($row, $p, $r->user(), $before, $v['reason'], 'individual');
            if (DB::table('report_validation_runs')->where('reporting_period_id', $p->id)->exists()) {
                $validation = app(EvidenceValidation::class)->evaluate($p, $r->user());
                if ($v['review_status'] === 'approved') {
                    abort_unless(in_array($validation['records'][$row->id]['status'] ?? null, ['AUTO_VALIDATED', 'HUMAN_VERIFIED']), 422, 'Material evidence exceptions remain. Correct the source or saved values before approving this exception.');
                }
            }

            return $row;
        }, 5);

        return response()->json(['data' => $out, 'message' => 'Review saved with correction history. Original extracted evidence is unchanged.']);
    }

    public function preview(Request $r, string $project, string $report)
    {
        $p = $this->period($r, $project, $report);
        $r->validate(['approved_only' => 'nullable|boolean']);
        $q = $this->query($p)->where('review_status', '!=', 'rejected');
        if ($r->boolean('approved_only')) {
            $q->where('review_status', 'approved');
        }
        $rows = $q->where('kind', '!=', 'unclassified')->get();
        $kpis = [];
        foreach ($rows->where('kind', 'report_metrics')->groupBy(fn ($v) => $v->data['metric_key']) as $key => $group) {
            $values = $group->map(fn ($v) => $v->data['value'])->unique()->values();
            $first = $group->first();
            $kpis[] = ['metric_key' => $key, 'value' => $values->count() === 1 ? $values[0] : null, 'unit' => $first->data['unit'], 'observations' => $group->map(fn ($v) => ['record_id' => $v->id, 'value' => $v->data['value'], 'review_status' => $v->review_status])->values(), 'conflict' => $values->count() > 1];
        }
        $latest = $this->current($p);
        $payload = ['kpis' => $kpis, 'keyword_summary' => $rows->where('kind', 'keyword_rankings')->groupBy(fn ($v) => $v->data['group'])->map->count(), 'seo_activity_counts' => $rows->where('kind', 'seo_activities')->groupBy(fn ($v) => $v->data['category'])->map->count(), 'event_count_sum' => $rows->where('kind', 'event_metrics')->sum(fn ($v) => $v->data['count']), 'ai_evidence_count' => $rows->where('kind', 'ai_visibility_evidence')->count(), 'approved_only' => $r->boolean('approved_only'), 'stale' => ! $latest || $latest->status !== 'completed' || $latest->fingerprint !== $this->engine->fingerprint($p, $this->engine->snapshot($p)), 'granularity' => 'reported_period_totals_only', 'review_notice' => 'Internal structured preview. Pending records are observations, not approved client reporting data.'];
        foreach (['traffic_channels', 'device_metrics', 'landing_page_metrics', 'event_metrics'] as $kind) {
            $payload[$kind] = $rows->where('kind', $kind)->values()->map(fn ($v) => ['id' => $v->id, 'data' => $v->data, 'review_status' => $v->review_status]);
        }

        return response()->json(['data' => $payload]);
    }
}
