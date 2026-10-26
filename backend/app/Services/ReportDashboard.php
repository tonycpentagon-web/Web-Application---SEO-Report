<?php

namespace App\Services;

use App\Models\NormalizedRecord;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Read-only draft projection. No reporting values, review decisions or report state are persisted here. */
class ReportDashboard
{
    public function __construct(public RecordReview $reviews) {}

    public function processingIssues(ReportingPeriod $p): array
    {
        $run = \App\Models\NormalizationRun::where('reporting_period_id', $p->id)->latest()->orderByDesc('id')->first();
        $issues = [];
        foreach ($run?->summary['classifications'] ?? [] as $classification) {
            if (collect($classification['labels'])->contains('type', 'keyword_ranking') && !NormalizedRecord::where('reporting_period_id', $p->id)->where('active', true)->where('kind', 'keyword_rankings')->whereHas('sources', fn($q) => $q->where('document_id', $classification['document_id']))->exists()) {
                $name = $p->documents()->find($classification['document_id'])?->original_filename ?? 'Keyword source';
                $issues[] = ['section' => 'keywords', 'document' => $name, 'message' => 'Keyword source detected, but no usable keyword rows were generated. Inspect the source table and regenerate the report.'];
            }
        }
        return $issues;
    }

    public function build(ReportingPeriod $p, User $user, bool $includeProvisional = true, bool $lockSources = false): array
    {
        $context = $this->reviews->context($p, $lockSources);
        $validation = app(EvidenceValidation::class)->current($p);
        $rows = NormalizedRecord::where('reporting_period_id', $p->id)->where('project_id', $p->project_id)->where('active', true)->where('kind', '!=', 'unclassified')->where('review_status', '!=', 'rejected')->with('sources')->orderBy('kind')->orderBy('id')->get();
        $context['review_permission'] = $this->reviews->canReview($user, $p);
        $context['verifications'] = DB::table('record_verifications')->whereIn('normalized_record_id', $rows->pluck('id'))->orderByDesc('created_at')->orderByDesc('id')->get()->groupBy('normalized_record_id');
        $records = [];
        $unresolved = [];
        $mapped = [];
        $unsafe = 0;
        $rejectedIds = NormalizedRecord::where('reporting_period_id', $p->id)->where('project_id', $p->project_id)->where('review_status', 'rejected')->pluck('id')->all();
        foreach ($rows as $row) {
            $e = $this->reviews->eligibility($row, $p, $user, $context);
            $issues = array_values(array_filter($e['reasons'], fn ($reason) => ! in_array($reason, ['Review permission is required.', 'Already approved; the existing decision will be preserved.'])));
            foreach ($e['warnings'] as $warning) {
                $mapped[$warning['id']] = true;
                if ($warning['blocking'] && ! $warning['resolved']) {
                    $unresolved[$warning['id']] = true;
                }
            }
            $provisional = $row->review_status !== 'approved' || ! $e['verified'] || (bool) $issues;
            $assessment = $validation['records'][$row->id] ?? null;
            if ($validation) {
                $provisional = ! $validation['current'] || ! in_array($assessment['status'] ?? null, ['AUTO_VALIDATED', 'HUMAN_VERIFIED']);
                if (($assessment['status'] ?? null) === 'NOT_APPLICABLE') {
                    continue;
                }$issues = $assessment['issues'] ?? ['Current evidence validation is required.'];
            }
            if ($provisional) {
                $unsafe++;
            }
            if (! $includeProvisional && $provisional) {
                continue;
            }
            $requirements = [];
            if ($row->review_status !== 'approved') {
                $requirements[] = ['code' => 'approval_required', 'message' => 'A project manager or administrator must explicitly approve this record after checking its evidence and validation.'];
            }
            if (! $e['verified']) {
                $requirements[] = ['code' => 'human_verification_'.$e['verification_state'], 'message' => $e['verification_state'] === 'outdated'
                    ? 'The previous human verification does not match the current evidence, values or warning context. Inspect the saved values, resolve every blocking warning and record source verification again.'
                    : 'No explicit human source verification has been recorded. Inspect the original evidence, enter source inspection notes and a resolution for each blocking warning, then select Record source verification.'];
            }
            foreach ($e['warnings'] as $warning) {
                if ($warning['blocking'] && ! $warning['resolved']) {
                    $requirements[] = ['code' => $warning['code'], 'warning_id' => $warning['id'], 'message' => $warning['message']];
                }
            }
            foreach ($issues as $issue) {
                if ($issue === 'Explicit human source verification is required.' || collect($e['warnings'])->contains(fn ($w) => str_starts_with($issue, $w['code'].': '))) {
                    continue;
                }
                $requirements[] = ['code' => 'validation_required', 'message' => $issue];
            }
            if ($validation) {
                $requirements = array_map(fn ($message) => ['code' => 'evidence_validation', 'message' => $message], $issues);
            }
            $sources = $row->sources->where('normalization_run_id', $row->normalization_run_id)->values();
            $records[] = ['id' => $row->id, 'kind' => $row->kind, 'section_key' => $row->section_key, 'data' => $row->data, 'revision' => $row->revision, 'review_status' => $row->review_status,
                'validation_status' => $assessment['status'] ?? null, 'human_verified' => $e['verified'], 'provisional' => $provisional, 'issues' => $issues, 'sources' => $sources->toArray(),
                'verification' => ['state' => $e['verification_state'], 'actor_id' => $e['verified_by'], 'verified_at' => $e['verified_at'], 'last_verified_at' => $e['last_verified_at']],
                'validation_warnings' => $e['warnings'], 'outstanding_requirements' => $requirements];
        }
        $warnings = [];
        foreach ($context['warnings'] as $w) {
            // Preserve warnings, including those concerning unsupported/missing data with no business row.
            $blocking = $this->reviews->warningIsBlocking($w);
            $rejected = $w->normalized_record_id && in_array($w->normalized_record_id, $rejectedIds);
            $resolved = $rejected || (isset($mapped[$w->id]) && ! isset($unresolved[$w->id]));
            if ($blocking && ! $resolved) {
                $unresolved[$w->id] = true;
            }
            $warnings[] = ['id' => $w->id, 'code' => $w->code, 'message' => $w->message, 'severity' => $w->severity, 'blocking' => $blocking, 'resolved_for_included_records' => $resolved,
                'document_id' => $w->document_id, 'record_id' => $w->normalized_record_id, 'source' => json_decode($w->source, true)];
        }
        $reasons = [];
        if ($p->requires_regeneration) {
            $reasons[] = 'Sources changed or generated data was reset. Generate and verify this Draft again.';
        }
        if (! $context['current']) {
            $reasons[] = 'Normalization is missing, incomplete or stale.';
        }
        if ($rows->isEmpty()) {
            $reasons[] = 'No supported non-rejected reporting data is available.';
        }
        if ($unsafe) {
            $reasons[] = $unsafe.' records still require approval, source verification or validation.';
        }
        if ($unresolved) {
            $reasons[] = count($unresolved).' blocking validation warnings remain unresolved.';
        }
        if ($validation) {
            $reasons = [];
            $unresolved = [];
            foreach ($warnings as &$warning) {
                $warning['blocking'] = false;
                $warning['resolved_for_included_records'] = false;
            }unset($warning);
            if (! $validation['current']) {
                $reasons[] = 'Evidence validation is stale. Re-evaluate current sources.';
            }if (! $validation['summary']['data_ready']) {
                $reasons[] = 'Material evidence exceptions or mandatory data gaps remain.';
            }foreach ($validation['summary']['document_issues'] as $issue) {
                $reasons[] = $issue['message'];
            }if ($unsafe) {
                $reasons[] = $unsafe.' material record exceptions require attention.';
            }if (! $context['current'] || $p->requires_regeneration) {
                $reasons[] = 'Normalization is incomplete or stale.';
            }
        }
        $uniqueRecords = [];
        foreach ($records as $record) {
            $identity = hash('sha256', json_encode([$record['kind'], $record['data']]));
            if (isset($uniqueRecords[$identity])) {
                $uniqueRecords[$identity]['sources'] = array_values(array_unique([...$uniqueRecords[$identity]['sources'], ...$record['sources']], SORT_REGULAR));
                $uniqueRecords[$identity]['provisional'] = $uniqueRecords[$identity]['provisional'] || $record['provisional'];
                $uniqueRecords[$identity]['issues'] = array_values(array_unique([...$uniqueRecords[$identity]['issues'], ...$record['issues']]));
            } else {
                $uniqueRecords[$identity] = $record;
            }
        }
        $records = array_values($uniqueRecords);
        $kpis = [];
        foreach (collect($records)->where('kind', 'report_metrics')->groupBy('data.metric_key') as $key => $observations) {
            $values = $observations->pluck('data.value')->unique()->values();
            $kpis[] = ['metric_key' => $key, 'label' => $observations->first()['data']['label'] ?? $key, 'unit' => $observations->first()['data']['unit'], 'value' => $values->count() === 1 ? $values[0] : null,
                'conflict' => $values->count() > 1, 'provisional' => $observations->contains('provisional', true), 'observations' => $observations->values()->all()];
        }

        return ['source_periods' => $validation && $validation['current'] ? ($validation['summary']['source_periods'] ?? []) : [], 'mode' => 'internal_draft_preview', 'report' => ['id' => $p->id, 'title' => $p->title, 'status' => $p->status, 'version' => $p->version, 'start_date' => $p->start_date->format('Y-m-d'), 'end_date' => $p->end_date->format('Y-m-d')],
            'project' => ['id' => $p->project_id, 'name' => $p->project->name, 'website' => $p->project->website, 'client_name' => $p->project->client?->name,
                'logo_url' => $p->project->client?->logo_path ? '/api/clients/'.$p->project->client_id.'/logo' : null],
            'include_provisional' => $includeProvisional, 'stale' => ! $context['current'], 'normalization_run_id' => $context['run']?->id,
            'coverage' => ['business_records' => $rows->count(), 'approved' => $rows->where('review_status', 'approved')->count(), 'human_verified' => count(array_filter($records, fn ($row) => $row['human_verified'])), 'provisional' => $unsafe, 'shown' => count($records)],
            'publication' => ['allowed' => ! $reasons, 'blocking_warning_count' => count($unresolved), 'reasons' => $reasons], 'kpis' => $kpis, 'records' => $records, 'warnings' => $warnings,
            'granularity' => 'reported_period_totals_only', 'generated_at' => now()->toIso8601String()];
    }

    public function assertPublishable(ReportingPeriod $p, User $user): void
    {
        $data = $this->build($p, $user, true, true);
        abort_unless($data['publication']['allowed'], 409, 'Publishing and client sharing are blocked: '.implode(' ', $data['publication']['reasons']));
    }
}
