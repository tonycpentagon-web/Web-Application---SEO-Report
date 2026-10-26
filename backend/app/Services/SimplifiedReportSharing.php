<?php

namespace App\Services;

use App\Jobs\GenerateReportPdf;
use App\Models\{ReportingPeriod, ReportPublication, ReportPdfExport, User};
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;

/** Employee sharing policy. Assessments are additive; legacy review history is never rewritten. */
class SimplifiedReportSharing
{
    public function assess(ReportingPeriod $p): array
    {
        $validation = app(EvidenceValidation::class)->current($p);
        $context = app(RecordReview::class)->context($p);
        $blockers = [];
        $warnings = [];
        $needsGeneration = !$context['current'] || $p->requires_regeneration;
        $needsValidation = !$needsGeneration && (!$validation || !$validation['current']);
        if ($needsGeneration) {
            $blockers[] = 'Source files, extraction or reporting dates changed since the last completed generation. Regenerate Report using the existing uploads.';
        } elseif ($needsValidation) {
            $blockers[] = 'Generation matches the current source files. Evidence checks need refreshing because validation rules or evidence context changed.';
        }
        // Do not present a previous ruleset's stale mismatch decisions as current facts.
        $currentValidation = $validation && $validation['current'] ? $validation : null;
        $periods = $currentValidation['summary']['source_periods'] ?? [];
        $confirmations = array_values(array_filter($periods, fn ($period) => $period['requires_confirmation'] && !($period['scope_confirmed'] ?? false) && !($period['coverage_accepted'] ?? false)));
        foreach ($periods as $period) {
            if (($period['candidate_outside_period'] ?? false) && !($period['coverage_accepted'] ?? false)) {
                $blockers[] = $period['document'].': OCR suggests dates outside the selected month. Inspect and correct the source date evidence before assigning it to this month.';
            }
        }
        if ($p->project->status !== 'active' || $p->project->client?->status === 'archived') {
            $blockers[] = 'Restore the client and project before sharing.';
        }
        if ($p->start_date > $p->end_date) {
            $blockers[] = 'Correct the reporting dates.';
        }
        $records = \App\Models\NormalizedRecord::where('reporting_period_id', $p->id)->where('active', true)->where('kind', '!=', 'unclassified')->where('review_status', '!=', 'rejected')->get()->keyBy('id');
        if ($records->isEmpty()) {
            $blockers[] = 'No readable reporting data. Replace or retry the source documents and generate again.';
        }
        foreach ($currentValidation['records'] ?? [] as $id => $result) {
            if (! $records->has($id)) {
                continue;
            }
            if ($result['status'] === 'INVALID') {
                foreach ($result['issues'] as $issue) {
                    $blockers[] = $issue;
                }
            } elseif ($result['status'] === 'NEEDS_REVIEW') {
                $warnings[] = ['section' => ReportDelivery::SECTION_MAP[$records[$id]->section_key] ?? $records[$id]->section_key, 'record_id' => $id, 'message' => implode(' ', $result['issues'])];
            }
        }
        foreach ($currentValidation['summary']['document_issues'] ?? [] as $issue) {
            // Unknown dates and incomplete extraction can be disclosed; contradictory dates/client identity cannot.
            if (in_array($issue['code'], ['client_project_mismatch', 'date_range_mismatch'])) {
                $blockers[] = ($issue['document_name'] ?? 'Source').': '.$issue['message'];
            } else {
                $rangeText = collect($issue['source_period']['ranges'] ?? [])->map(fn ($v) => $v['start'].' to '.$v['end'])->implode('; ');
                $warnings[] = ['section' => 'sources', 'document' => $issue['document_name'] ?? 'Source', 'message' => ($rangeText ? 'Printed source coverage: '.$rangeText.'. ' : '').(!empty($issue['source_period']['uncovered_dates']) ? 'Dates not covered by this printed range: '.implode(', ', $issue['source_period']['uncovered_dates']).'. ' : '').$issue['message']];
            }
        }
        foreach ($context['warnings'] as $warning) {
            if ($warning->code === 'keyword_extraction_empty') $blockers[] = 'Keyword Ranking source detected but no usable rows were generated. Inspect its table extraction and regenerate before sharing an updated report.';
            if (! $needsGeneration && ! $warning->normalized_record_id && $warning->code !== 'date_range_mismatch') {
                $message = match($warning->code) {
                    'unclassified_content' => 'Additional source context is preserved for inspection; only supported observations are charted.',
                    'uncertain_ocr' => 'OCR-derived observations remain provisional and may contain recognition errors.',
                    default => $warning->message,
                };
                $warnings[] = ['section' => 'sources', 'message' => $message];
            }
        }
        $summary = DB::table('report_summary_revisions')->where('reporting_period_id', $p->id)->orderByDesc('revision')->first();
        if ($summary && $summary->source_fingerprint !== app(\App\Http\Controllers\ReportSummaryController::class)->fingerprint($p)) {
            $blockers[] = 'Saved commentary refers to older sources. Save updated commentary or regenerate it before sharing.';
        }
        foreach ($warnings as &$warning) {
            $warning['message'] = str_replace(['Recover the missing unit before approval.', 'A human must inspect the screenshot before making a brand-appearance claim.', 'A clear human brand-appearance decision is required.'], ['Some source content is unavailable; this report may be incomplete.', 'Screenshot observation only; brand appearance has not been verified.', 'Brand appearance is unverified.'], $warning['message']);
        } unset($warning);
        $warnings = collect($warnings)->unique(fn ($w) => json_encode([$w['section'], $w['message'], $w['document'] ?? null]))->values()->all();

        return ['period_mismatches'=>array_values(array_filter($periods,fn($period)=>(($period['status']==='mismatch')||($period['candidate_outside_period']??false))&&!($period['coverage_accepted']??false))), 'requires_regeneration' => (bool)$needsGeneration, 'requires_validation_refresh' => (bool)$needsValidation, 'source_periods' => $periods, 'period_confirmations' => $confirmations, 'eligible' => ! $blockers, 'blockers' => array_values(array_unique($blockers)), 'warnings' => $warnings, 'requires_acknowledgment' => (bool) $warnings,
            'resolution' => $blockers ? 'Replace or remove the affected source, correct the month if needed, then Generate SEO Report again. No individual record approvals are required.' : null,
            'fingerprint' => hash('sha256', json_encode([$p->version, $validation['fingerprint'] ?? null, $summary?->id, $blockers, $warnings, $periods]))];
    }

    public function preview(ReportingPeriod $p, User $user): array
    {
        $snapshot = app(ReportDelivery::class)->snapshot($p, $user, true, true);
        $validation = app(EvidenceValidation::class)->current($p);
        $snapshot['source_periods'] = $validation && $validation['current'] ? ($validation['summary']['source_periods'] ?? []) : [];
        $snapshot['processing_issues'] = app(ReportDashboard::class)->processingIssues($p);
        $snapshot['disclosures'] = $this->assess($p)['warnings'];
        $deduped = [];
        foreach ($snapshot['records'] as $row) {
            $key = hash('sha256', json_encode([$row['kind'], $row['data'], $row['image']]));
            if (isset($deduped[$key])) $deduped[$key]['sources'] = array_values(array_unique([...$deduped[$key]['sources'], ...$row['sources']], SORT_REGULAR));
            else $deduped[$key] = $row;
        }
        $snapshot['records'] = array_values($deduped);
        if (($snapshot['summary_origin'] ?? null) === 'generated') $snapshot['summary'] = app(PublicationCommentary::class)->sections($snapshot);
        elseif (($snapshot['summary_state'] ?? '') === 'outdated') $snapshot['summary'] = [];
        return $snapshot;
    }

    public function share(ReportingPeriod $p, User $user, array $v): array
    {
        return DB::transaction(function () use ($p, $user, $v) {
            $p = ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active && app(WorkspaceAccess::class)->workReports($user, $p->project), 403);
            abort_unless($p->version === $v['version'], 409, 'Report changed. Reload before sharing.');
            $delivery = app(ReportDelivery::class);
            $pub = ReportPublication::where('reporting_period_id', $p->id)->orderByDesc('version')->first();
            $newPublication = ($v['new_publication'] ?? false) && $p->status === 'published';
            if ($p->status !== 'published' || $newPublication) {
                abort_unless($newPublication || in_array($p->status, ['draft', 'needs_review', 'approved']), 409, 'Restore the report to Draft before sharing.');
                app(RecordReview::class)->context($p, true);
                $assessment = $this->assess($p);
                abort_unless($assessment['eligible'], 409, implode(' ', $assessment['blockers']).' '.$assessment['resolution']);
                abort_unless(hash_equals($assessment['fingerprint'], $v['fingerprint']), 409, 'Sources or warnings changed. Reload the sharing summary.');
                abort_if($assessment['period_confirmations'] && !($v['confirm_source_periods'] ?? false), 422, 'Explicitly confirm the listed uncertain or partial source periods before sharing. Printed ranges will remain disclosed.');
                $periods = $assessment['source_periods'];
                if ($assessment['period_confirmations']) {
                    $input = app(EvidenceValidation::class)->input($p);
                    foreach ($assessment['period_confirmations'] as $period) {
                        DB::table('report_source_scope_verifications')->insert(['id' => (string) Str::ulid(), 'reporting_period_id' => $p->id, 'document_id' => $period['document_id'], 'actor_id' => $user->id, 'fingerprint' => $input['scopes'][$period['document_id']], 'reason' => 'Employee explicitly assigned this source to '.$p->start_date->format('F Y').' with disclosed '.$period['status'].' coverage; printed dates remain '.json_encode($period['ranges']).'. This does not verify OCR values or supply missing days.', 'created_at' => now()]);
                    }
                    $delivery->event($p, null, $user, 'report.source_periods_confirmed', ['assessment_fingerprint' => $assessment['fingerprint'], 'documents' => $assessment['period_confirmations']]);
                    $periods = app(EvidenceValidation::class)->evaluate($p, $user, $newPublication)['summary']['source_periods'];
                }
                abort_if($assessment['requires_acknowledgment'] && ! ($v['acknowledge_uncertainty'] ?? false), 422, 'Acknowledge the disclosed uncertainty before sharing.');
                $snapshot = $delivery->snapshot($p, $user, false, true);
                $snapshot['schema'] = 2;
                $snapshot['source_periods'] = $periods;
                $snapshot['source_period_confirmation'] = ['confirmed' => (bool)($v['confirm_source_periods'] ?? false), 'confirmed_at' => now()->toIso8601String()];
                $snapshot['disclosures'] = $assessment['warnings'];
                $snapshot['summary_state'] = 'source_backed';
                $snapshot['publication_version'] = (int) ReportPublication::where('reporting_period_id', $p->id)->max('version') + 1;
                $snapshot['report']['status'] = 'published';
                // Identical observations from repeated source files are represented once, with all citations retained.
                $deduped = [];
                foreach ($snapshot['records'] as $row) {
                    $key = hash('sha256', json_encode([$row['kind'], $row['data'], $row['image']]));
                    if (isset($deduped[$key])) {
                        $deduped[$key]['sources'] = array_values(array_unique([...$deduped[$key]['sources'], ...$row['sources']], SORT_REGULAR));
                    } else {
                        $deduped[$key] = $row;
                    }
                }
                $snapshot['records'] = array_values($deduped);
                if (($snapshot['summary_origin'] ?? null) === 'generated') {
                    $snapshot['summary'] = app(PublicationCommentary::class)->sections($snapshot);
                    $snapshot['summary_basis'] = 'deduplicated_publication_records';
                }
                $pub = ReportPublication::create(['reporting_period_id' => $p->id, 'project_id' => $p->project_id, 'version' => $snapshot['publication_version'], 'published_by' => $user->id, 'published_at' => now(), 'snapshot' => $snapshot, 'snapshot_hash' => hash('sha256', json_encode($snapshot))]);
                $p->update(['status' => 'published', 'version' => $p->version + 1, 'updated_by' => $user->id]);
                $delivery->event($p, $pub->id, $user, 'report.published', ['workflow' => 'simplified', 'supersedes_with_existing_links_preserved' => $newPublication, 'uncertainty_acknowledged' => (bool) ($v['acknowledge_uncertainty'] ?? false), 'assessment' => $assessment['fingerprint']]);
            }
            abort_unless($pub, 409, 'No published snapshot available.');
            $delivery->assertAvailable($pub);
            $expiry = $v['expires_at'] ?? now()->addDays(30)->toIso8601String();
            $token = bin2hex(random_bytes(32));
            $id = (string) Str::ulid();
            DB::table('report_share_links')->insert(['id' => $id, 'publication_id' => $pub->id, 'token_hash' => hash('sha256', $token), 'encrypted_token' => Crypt::encryptString($token), 'access_mode' => 'public', 'label' => 'Client report', 'expires_at' => $expiry, 'allow_download' => true, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            app(ReportWorkspace::class)->file($p, $user);
            $delivery->event($p, $pub->id, $user, 'share.created', ['share_id' => $id, 'access_mode' => 'public', 'expires_at' => $expiry]);
            $export = ReportPdfExport::where('publication_id', $pub->id)->whereIn('status', ['queued', 'rendering', 'completed'])->where('type', 'published')->where('sections', json_encode(ReportDelivery::SECTIONS))->first();
            if (! $export) {
                $export = ReportPdfExport::create(['profile' => 'client_preview', 'reporting_period_id' => $p->id, 'publication_id' => $pub->id, 'generated_by' => $user->id, 'type' => 'published', 'payload' => $pub->snapshot, 'sections' => ReportDelivery::SECTIONS]);
                GenerateReportPdf::dispatch($export->id)->afterCommit();
            }

            return ['id' => $id, 'publication_id' => $pub->id, 'export_id' => $export->id, 'url' => rtrim(config('insight.frontend_url'), '/').'/public-report#'.$token, 'expires_at' => $expiry];
        }, 5);
    }
}
