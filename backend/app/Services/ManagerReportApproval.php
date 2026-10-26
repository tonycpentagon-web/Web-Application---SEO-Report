<?php

namespace App\Services;

use App\Http\Controllers\ReportSummaryController;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ManagerReportApproval
{
    public function context(ReportingPeriod $p, User $actor, ?array $validation = null): array
    {
        $validation ??= app(EvidenceValidation::class)->current($p);
        $summary = DB::table('report_summary_revisions')->where('reporting_period_id', $p->id)->orderByDesc('revision')->first();
        $sections = $summary ? json_decode($summary->sections, true) : [];
        $summaryCurrent = $summary && hash_equals($summary->source_fingerprint, app(ReportSummaryController::class)->fingerprint($p));
        $fingerprint = hash('sha256', json_encode([$validation['fingerprint'] ?? null, $summary]));
        $approval = DB::table('report_manager_approvals')->where('reporting_period_id', $p->id)->where('fingerprint', $fingerprint)->orderByDesc('id')->first();
        $approved = $approval && in_array($p->status, ['approved', 'published']) && ($p->status === 'published' || $approval->report_revision === $p->version);
        $reasons = [];
        if (! $validation || ! $validation['current']) {
            $reasons[] = 'Run current evidence validation first.';
        } elseif (! $validation['summary']['data_ready']) {
            $reasons[] = 'Resolve material validation exceptions and missing mandatory data first.';
        }
        if (! $summaryCurrent) {
            $reasons[] = 'Generate or save current source-bound commentary.';
        }
        foreach (config('delivery.required_sections') as $key) {
            if (! trim($sections[$key] ?? '')) {
                $reasons[] = 'Required commentary missing: '.$key;
            }
        }

        return ['approved' => (bool) $approved, 'approval' => $approval ? ['actor_id' => $approval->actor_id, 'created_at' => $approval->created_at, 'report_revision' => $approval->report_revision] : null, 'fingerprint' => $fingerprint, 'validation_fingerprint' => $validation['fingerprint'] ?? null, 'summary_revision' => $summary?->revision ?? 0, 'can_approve' => ! $reasons && in_array($p->status, ['draft', 'needs_review']) && app(WorkspaceAccess::class)->manageProject($actor, $p->project), 'reasons' => $reasons];
    }

    public function approve(ReportingPeriod $p, User $actor, array $v): array
    {
        return DB::transaction(function () use ($p, $actor, $v) {
            $p = ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active && app(WorkspaceAccess::class)->manageProject($actor, $p->project), 403);
            app(RecordReview::class)->context($p, true);
            $validation = app(EvidenceValidation::class)->current($p);
            $context = $this->context($p, $actor, $validation);
            abort_unless($p->version === $v['version'] && hash_equals($context['fingerprint'], $v['fingerprint']), 409, 'Report evidence or commentary changed. Reload the readiness summary.');
            abort_unless($context['can_approve'], 409, implode(' ', $context['reasons']));
            $summary = DB::table('report_summary_revisions')->where('reporting_period_id', $p->id)->orderByDesc('revision')->first();
            DB::table('report_summary_approvals')->insertOrIgnore(['id' => (string) Str::ulid(), 'summary_revision_id' => $summary->id, 'fingerprint' => $summary->source_fingerprint, 'actor_id' => $actor->id, 'reason' => $v['reason'], 'created_at' => now(), 'updated_at' => now()]);
            $before = $p->status;
            $p->forceFill(['status' => 'approved', 'version' => $p->version + 1, 'updated_by' => $actor->id])->save();
            DB::table('report_manager_approvals')->insert(['id' => (string) Str::ulid(), 'reporting_period_id' => $p->id, 'validation_run_id' => $validation['id'], 'actor_id' => $actor->id, 'report_revision' => $p->version, 'summary_revision' => $summary->revision, 'fingerprint' => $context['fingerprint'], 'reason' => $v['reason'], 'created_at' => now()]);
            app(ReportDelivery::class)->event($p, null, $actor, 'report.manager_approved', ['before' => $before, 'after' => 'approved', 'validation_run_id' => $validation['id'], 'report_revision' => $p->version, 'summary_revision' => $summary->revision, 'reason' => $v['reason'], 'scope' => 'Entire report and commentary; no individual human verification was created.']);

            return $this->context($p,$actor,$validation);
        }, 5);
    }
}
