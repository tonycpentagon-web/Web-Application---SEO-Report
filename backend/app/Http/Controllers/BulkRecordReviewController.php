<?php

namespace App\Http\Controllers;

use App\Models\NormalizedRecord;
use App\Models\ReportingPeriod;
use App\Services\EvidenceValidation;
use App\Services\RecordReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BulkRecordReviewController extends Controller
{
    public function __construct(public RecordReview $reviews) {}

    private function period(Request $r, string $project, string $report): ReportingPeriod
    {
        $p = $this->reviews->access->report($r->user(), $project, $report);
        abort_unless($this->reviews->canReview($r->user(), $p), 403, 'A project manager or administrator must review normalized data.');

        return $p;
    }

    private function locked(Request $r, ReportingPeriod $p): ReportingPeriod
    {
        $p = ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
        abort_unless($this->reviews->canReview($r->user(), $p), 409, 'Report access or workflow state changed.');

        return $p;
    }

    public function verify(Request $r, string $project, string $report, string $record)
    {
        $p = $this->period($r, $project, $report);
        $v = $r->validate(['revision' => 'required|integer|min:1', 'confirmed' => 'required|accepted', 'reason' => 'required|string|min:10|max:2000', 'resolutions' => 'present|array|max:100', 'resolutions.*.warning_id' => 'required|ulid|distinct', 'resolutions.*.reason' => 'required|string|min:10|max:2000']);
        $out = DB::transaction(function () use ($r, $p, $record, $v) {
            $p = $this->locked($r, $p);
            $row = NormalizedRecord::where('reporting_period_id', $p->id)->where('project_id', $p->project_id)->where('active', true)->lockForUpdate()->findOrFail($record);
            abort_unless($row->revision === $v['revision'], 409, 'This record changed. Reload before verifying.');
            $context = $this->reviews->context($p, true);
            $this->reviews->verify($row, $p, $r->user(), $context, $v['reason'], $v['resolutions']);

            return $this->reviews->eligibility($row, $p, $r->user(), $context);
        }, 5);

        if (DB::table('report_validation_runs')->where('reporting_period_id', $p->id)->exists()) {
            app(EvidenceValidation::class)->evaluate($p, $r->user());
        }

        return response()->json(['data' => $out, 'message' => 'Source inspection and warning resolutions recorded. No approval decision was changed.']);
    }

    public function review(Request $r, string $project, string $report)
    {
        return $this->batch($r, $project, $report, false);
    }

    public function preview(Request $r, string $project, string $report)
    {
        return $this->batch($r, $project, $report, true);
    }

    private function batch(Request $r, string $project, string $report, bool $preview)
    {
        $p = $this->period($r, $project, $report);
        $rules = ['record_ids' => 'required|array|min:1|max:250', 'record_ids.*' => 'required|ulid|distinct', 'revisions' => 'required|array', 'revisions.*' => 'required|integer|min:1', 'decision' => 'required|in:approved,rejected'];
        if (! $preview) {
            $rules += ['reason' => 'required|string|min:10|max:2000', 'confirmed' => 'required|accepted', 'request_key' => 'required|uuid'];
        }
        $v = $r->validate($rules);
        sort($v['record_ids']);
        ksort($v['revisions']);
        abort_unless(array_keys($v['revisions']) === $v['record_ids'], 422, 'Supply exactly one revision for each selected record.');
        $hash = hash('sha256', json_encode($v));
        $result = DB::transaction(function () use ($r, $p, $v, $hash, $preview) {
            $p = $this->locked($r, $p);
            if (! $preview) {
                $previous = DB::table('bulk_review_requests')->where('reporting_period_id', $p->id)->where('request_key', $v['request_key'])->first();
                if ($previous) {
                    abort_unless($previous->actor_id === $r->user()->id && $previous->payload_hash === $hash, 409, 'This request key was already used for another review.');

                    return json_decode($previous->response, true);
                }
            }
            $rows = NormalizedRecord::where('reporting_period_id', $p->id)->where('project_id', $p->project_id)->where('active', true)->whereIn('id', $v['record_ids'])->orderBy('id')->lockForUpdate()->get();
            // Reject foreign/unknown IDs atomically before touching any permitted record.
            abort_unless($rows->count() === count($v['record_ids']) && ! array_diff($v['record_ids'], $rows->pluck('id')->all()), 404, 'One or more records are unavailable in this reporting period.');
            $context = $this->reviews->context($p, true);
            abort_unless($context['current'], 409, 'Sources changed or normalization is incomplete. Reload and normalize before review.');
            $validation = app(EvidenceValidation::class)->current($p);
            $results = [];
            $categories = [];
            $eligible = 0;
            foreach ($rows as $row) {
                $e = $this->reviews->eligibility($row, $p, $r->user(), $context);
                $reasons = $v['decision'] === 'approved' ? $e['reasons'] : [];
                if ($v['decision'] === 'approved' && $validation && (! $validation['current'] || ! in_array($validation['records'][$row->id]['status'] ?? null, ['AUTO_VALIDATED', 'HUMAN_VERIFIED']))) {
                    $reasons[] = 'Material evidence validation exceptions remain; bulk approval cannot waive them.';
                }
                if ($row->revision !== $v['revisions'][$row->id]) {
                    $reasons[] = 'This record changed. Reload before reviewing.';
                }
                $same = $row->review_status === $v['decision'];
                if ($same && $v['decision'] === 'rejected') {
                    $reasons[] = 'Already rejected; the existing decision will be preserved.';
                }
                $status = $reasons ? 'excluded' : ($preview ? 'eligible' : 'updated');
                if (! $reasons) {
                    $eligible++;
                    $categories[$row->kind] = ($categories[$row->kind] ?? 0) + 1;
                    if (! $preview) {
                        $before = $row->toArray();
                        $row->update(['review_status' => $v['decision'], 'reviewed_by' => $r->user()->id, 'reviewed_at' => now(), 'revision' => $row->revision + 1]);
                        $this->reviews->audit($row, $p, $r->user(), $before, $v['reason'], 'bulk', $v['request_key']);
                    }
                }
                $results[] = ['id' => $row->id, 'kind' => $row->kind, 'status' => $status, 'review_status' => $row->review_status, 'revision' => $row->revision, 'reasons' => array_values(array_unique($reasons))];
            }
            $out = ['report_id' => $p->id, 'report_title' => $p->title, 'period' => $p->start_date->format('Y-m-d').' — '.$p->end_date->format('Y-m-d'), 'selected_count' => count($results), 'eligible_count' => $eligible, 'excluded_count' => count($results) - $eligible, 'categories' => $categories, 'results' => $results];
            if (! $preview) {
                DB::table('bulk_review_requests')->insert(['id' => (string) Str::ulid(), 'reporting_period_id' => $p->id, 'actor_id' => $r->user()->id, 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'response' => json_encode($out), 'created_at' => now(), 'updated_at' => now()]);
            }

            return $out;
        }, 5);

        if (! $preview && DB::table('report_validation_runs')->where('reporting_period_id', $p->id)->exists()) {
            app(EvidenceValidation::class)->evaluate($p, $r->user());
        }

        return response()->json(['data' => $result, 'message' => $preview ? 'Review eligibility checked.' : $result['eligible_count'].' records updated; '.$result['excluded_count'].' excluded.']);
    }
}
