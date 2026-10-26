<?php

namespace App\Services;

use App\Models\NormalizedRecord;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Additive assessments. Machine validation never writes decisions, corrections or human verification. */
class EvidenceValidation
{
    public const VERSION = 'evidence-validation/1.5';

    public const STATES = ['AUTO_VALIDATED', 'HUMAN_VERIFIED', 'NEEDS_REVIEW', 'INVALID', 'NOT_APPLICABLE'];

    public function __construct(public RecordReview $reviews) {}

    public function input(ReportingPeriod $p): array
    {
        $c = $this->reviews->context($p);
        $rows = NormalizedRecord::where('reporting_period_id', $p->id)->where('project_id', $p->project_id)->where('active', true)->with('sources')->orderBy('id')->get();
        $c['review_permission'] = true;
        $c['verifications'] = DB::table('record_verifications')->whereIn('normalized_record_id', $rows->pluck('id'))->orderByDesc('created_at')->orderByDesc('id')->get()->groupBy('normalized_record_id');
        $content = DB::table('document_extracted_content')->whereIn('extraction_run_id', $c['docs']->pluck('current_run_id')->filter())->orderBy('id')->get()->keyBy('id');
        $files = [];
        foreach ($c['docs'] as $d) {
            $path = Storage::disk('local')->path($d->storage_path);
            $files[$d->id] = is_file($path) ? hash_file('sha256', $path) : null;
        }
        $assets = [];
        foreach ($rows->flatMap->sources->pluck('asset_id')->filter()->unique() as $id) {
            $a = $c['assets']->get($id);
            $path = $a ? Storage::disk('local')->path($a->storage_path) : null;
            $assets[$id] = $path && is_file($path) ? hash_file('sha256', $path) : null;
        }
        $scopes = [];
        foreach ($c['docs'] as $doc) {
            $scopes[$doc->id] = hash('sha256', json_encode([$p->id, $p->project_id, $p->project->client_id, $p->project->client?->name, $p->project->website, $p->start_date->format('Y-m-d'), $p->end_date->format('Y-m-d'), $doc->getAttributes(), $content->where('document_id', $doc->id), $files[$doc->id], $doc->currentRun?->warnings]));
        }
        $scopeVerifications = DB::table('report_source_scope_verifications')->where('reporting_period_id', $p->id)->orderBy('id')->get();
        $fingerprint = hash('sha256', json_encode([self::VERSION, $scopeVerifications, $scopes, $c['fingerprint'], $c['run']?->id, $p->project_id, $p->project->website, $p->project->name, $p->project->client_id, $p->project->client?->name, $p->project->status, $p->project->client?->status, $rows->map(fn ($r) => [$r->getAttributes(), $r->sources->map->getAttributes()]), $content, $c['warnings'], $c['verifications'], $files, $assets]));

        return compact('c', 'rows', 'content', 'files', 'assets', 'fingerprint', 'scopes', 'scopeVerifications');
    }

    public function current(ReportingPeriod $p, ?array $input = null): ?array
    {
        $run = DB::table('report_validation_runs')->where('reporting_period_id', $p->id)->orderByDesc('id')->first();
        if (! $run) {
            return null;
        }
        $input ??= $this->input($p);
        $current = hash_equals($run->fingerprint, $input['fingerprint']);
        $results = DB::table('record_validation_results')->where('validation_run_id', $run->id)->get()->keyBy('normalized_record_id')->map(fn ($r) => ['status' => $current ? $r->status : 'NEEDS_REVIEW', 'issues' => $current ? json_decode($r->issues, true) : ['Validation is stale. Re-evaluate against current evidence.'], 'checks' => json_decode($r->checks, true), 'evidence' => json_decode($r->evidence, true)])->all();

        return ['id' => $run->id, 'current' => $current, 'fingerprint' => $run->fingerprint, 'evaluated_at' => $run->created_at, 'before' => json_decode($run->before_summary, true), 'summary' => json_decode($run->summary, true), 'records' => $results];
    }

    private function normalized(string $text): string
    {
        return mb_strtolower(preg_replace('/[^\pL\pN.:\/%_-]+/u', '', $text));
    }

    private function text(object $c): string
    {
        $data = json_decode($c->structured_json ?? '[]', true) ?? [];
        $values = [];
        array_walk_recursive($data, function ($v) use (&$values) {
            if (is_scalar($v)) {
                $values[] = (string) $v;
            }
        });

        return ($c->original_text ?? '')."\n".implode("\n", $values);
    }

    private function semantics($r): string
    {
        return json_encode(array_intersect_key($r->data, array_flip(['unit', 'definition', 'granularity', 'source_start_date', 'source_end_date', 'scope', 'aggregation_scope', 'attribution_model', 'property_id', 'site_url', 'dimensions', 'filters'])));
    }

    public function evaluate(ReportingPeriod $p, User $actor, bool $prepareUpdatedPublication = false): array
    {
        return DB::transaction(function () use ($p, $actor, $prepareUpdatedPublication) {
            $p = ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($p->status, ['draft', 'needs_review']) || ($prepareUpdatedPublication && app(DocumentAccess::class)->canPreparePublication($actor, $p)), 409, 'Re-evaluation requires an unpublished Draft or Review report. Published snapshots are never reprocessed.');
            $this->reviews->context($p, true);
            $in = $this->input($p);
            extract($in);
            $previous = $this->current($p, $in);
            $results = [];
            $docInfo = [];
            $documentIssues = [];
            foreach ($c['docs'] as $doc) {
                $native = $content->where('document_id', $doc->id)->filter(fn ($v) => in_array($v->content_type, ['text', 'worksheet_text', 'paragraph', 'heading']) && ! in_array($v->confidence_status, ['ocr', 'uncertain', 'low']));
                $text = $native->map(fn ($v) => $this->text($v))->implode("\n");
                $period = app(SourceReportingPeriod::class)->detect($content->where('document_id', $doc->id), $p);
                $scopeVerified = $scopeVerifications->contains(fn ($v) => $v->document_id === $doc->id && hash_equals($v->fingerprint, $scopes[$doc->id]));
                $period['scope_confirmed'] = $scopeVerified;
                $coverageAccepted = $scopeVerifications->contains(function($v) use($doc,$scopes,$period) {
                    if($v->document_id!==$doc->id || !hash_equals($v->fingerprint,$scopes[$doc->id])) return false;
                    $decision=json_decode($v->reason,true);
                    return is_array($decision) && ($decision['type'] ?? '')==='explicit_actual_source_coverage' && ($decision['ranges'] ?? null)===$period['ranges'];
                });
                $period['coverage_accepted']=$coverageAccepted;
                if($coverageAccepted) $period['coverage_policy']='Actual printed source coverage, explicitly assigned; not calendar-month totals.';

                $association = true;
                if (preg_match('/ADDRESSED[ \t]+TO[ \t]*:[ \t]*([^\r\n]+)/iu', $text, $address)) {
                    $expected = $this->normalized($p->project->client?->name ?? $p->project->client_name ?? $p->project->name);
                    $stated = $this->normalized($address[1]);
                    if (strlen($expected) > 4 && strlen($stated) > 4 && ! str_contains($stated, $expected) && ! str_contains($expected, $stated)) {
                        $association = false;
                        $documentIssues[] = ['code' => 'client_project_mismatch', 'document_id' => $doc->id, 'message' => 'The source addressee differs from the selected client. Inspect and replace the wrong-client document.', 'blocking' => true];
                    }
                }
                $identityText = $content->where('document_id', $doc->id)->map(fn ($v) => $this->text($v))->implode("\n");
                if (preg_match('/(?:^|[\r\n])\s*(?:Client|Client name|Prepared for|Addressed to)[ \t]*:[ \t]*([^\r\n]+)/iu', $identityText, $declared)) {
                    $expected = $this->normalized($p->project->client?->company_name ?: $p->project->client?->name ?: $p->project->name);
                    $actual = $this->normalized($declared[1]);
                    if (strlen($expected) > 3 && strlen($actual) > 3 && ! str_contains($actual, $expected) && ! str_contains($expected, $actual)) {
                        $association = false;
                        $documentIssues[] = ['code' => 'client_project_mismatch', 'document_id' => $doc->id, 'message' => 'Source client identity conflicts with this client. Replace the source.', 'blocking' => true];
                    }
                }
                if (preg_match('/(?:^|[\r\n])\s*(?:Website|Property URL|Site URL)\s*:\s*(https?:\/\/[^\s,]+)/iu', $identityText, $declared)) {
                    $expected = preg_replace('/^www\./i', '', parse_url($p->project->website, PHP_URL_HOST) ?? '');
                    $actual = preg_replace('/^www\./i', '', parse_url($declared[1], PHP_URL_HOST) ?? '');
                    if ($expected && $actual && strcasecmp($expected, $actual) !== 0) {
                        $association = false;
                        $documentIssues[] = ['code' => 'client_project_mismatch', 'document_id' => $doc->id, 'message' => 'Source website conflicts with this project. Replace the source.', 'blocking' => true];
                    }
                }
                $docInfo[$doc->id] = ['association' => $association, 'period' => $period, 'status' => $doc->extraction_status, 'filename' => $doc->original_filename, 'native_text' => $text];
                if ($period['status'] === 'mismatch' && !$coverageAccepted) {
                    $documentIssues[] = ['code' => 'date_range_mismatch', 'document_id' => $doc->id, 'message' => 'An explicit reporting-period header falls outside the selected month. Correct the source/report period, or explicitly select its actual overlapping coverage. A general uncertainty acknowledgment cannot resolve this mismatch.', 'blocking' => true, 'source_period' => $period];
                } elseif ($coverageAccepted) {
                    $documentIssues[]=['code'=>'explicit_cross_month_coverage','document_id'=>$doc->id,'message'=>'Employee explicitly selected the printed cross-month source coverage. These are not calendar-month totals; source dates and missing days remain disclosed.','blocking'=>false,'source_period'=>$period];
                } elseif ($period['requires_confirmation']) {
                    $message = $period['status'] === 'partial'
                        ? 'Source covers only part of the selected month. Preserve the printed dates and explicitly confirm publication with incomplete coverage.'
                        : 'The reporting period is uncertain. Confirm source assignment without claiming the dates or OCR values are verified.';
                    $documentIssues[] = ['code' => $period['status'] === 'partial' ? 'partial_period_coverage' : 'period_not_reported', 'document_id' => $doc->id, 'message' => $message, 'blocking' => !$scopeVerified, 'requires_confirmation' => !$scopeVerified, 'source_period' => $period];
                }
                if (in_array($doc->extraction_status, ['failed', 'queued', 'processing', 'unsupported', 'empty'])) {
                    $documentIssues[] = ['code' => 'extraction_unavailable', 'document_id' => $doc->id, 'message' => 'Source extraction is unavailable or incomplete. Retry extraction.', 'blocking' => true];
                }
                foreach ($doc->currentRun?->warnings ?? [] as $warning) {
                    $code = is_array($warning) ? ($warning['code'] ?? '') : (string) $warning;
                    if ((is_array($warning) && ($warning['severity'] ?? null) === 'error') || preg_match('/page_failed|page_error|page_limit|truncat|limit_exceeded|timeout|unreadable_page/i', $code)) {
                        $documentIssues[] = ['code' => 'material_extraction_gap', 'document_id' => $doc->id, 'message' => 'A source unit failed or was truncated. Recover the missing unit before approval.', 'blocking' => true];
                    }
                }
            }
            foreach ($c['warnings'] as $warning) {
                if (! $warning->normalized_record_id && $this->reviews->warningIsBlocking($warning) && ! in_array($warning->code, ['partial_extraction', 'uncertain_ocr', 'date_range_mismatch', 'missing_value'])) {
                    $documentIssues[] = ['code' => $warning->code, 'document_id' => $warning->document_id, 'message' => $warning->message, 'blocking' => true];
                }
            }
            $seen = [];
            foreach ($rows as $row) {
                $e = $this->reviews->eligibility($row, $p, $actor, $c);
                $issues = [];
                $hard = [];
                $checks = [];
                $evidence = [];
                $supported = $row->kind !== 'unclassified' && $row->review_status !== 'rejected';
                if (! $supported) {
                    $results[$row->id] = ['status' => 'NOT_APPLICABLE', 'issues' => [], 'checks' => ['Retained context or explicitly rejected observation; excluded from report values.'], 'evidence' => ['raw_value' => $row->raw_value]];

                    continue;
                }
                foreach ($e['reasons'] as $reason) {
                    if (str_starts_with($reason, 'Normalized values failed validation:') || in_array($reason, ['Original source evidence is missing.', 'Original source document is unavailable or its extraction changed.', 'Source content or image evidence is missing.', 'Referenced source content or image is unavailable.'])) {
                        $hard[] = $reason;
                    }if (in_array($reason, ['Explicit human source verification is required.', 'Already approved; the existing decision will be preserved.', 'Review permission is required.'])) {
                        continue;
                    }if (collect($e['warnings'])->contains(fn ($w) => str_starts_with($reason, $w['code'].': '))) {
                        continue;
                    }$issues[] = $reason;
                }
                foreach ($e['warnings'] as $w) {
                    if (in_array($w['code'], ['date_range_mismatch', 'partial_period_coverage', 'uncertain_period', 'partial_extraction', 'uncertain_ocr', 'duplicate_metric', 'unclassified_content', 'ranking_context_missing'])) {
                        continue;
                    }if ($w['blocking'] && ! $w['resolved']) {
                        $issues[] = $w['code'].': '.$w['message'];
                    }
                }
                if ($row->manual_override && ! $e['verified']) {
                    $issues[] = 'A corrected value requires current human verification; the correction is preserved.';
                }
                foreach ($row->sources->where('normalization_run_id', $row->normalization_run_id) as $s) {
                    $doc = $c['docs']->get($s->document_id);
                    $piece = $content->get($s->content_id);
                    $info = $docInfo[$s->document_id] ?? null;
                    if (! $doc || $doc->project_id !== $p->project_id || $doc->reporting_period_id !== $p->id || ! $s->location) {
                        $hard[] = 'Missing source location or wrong project/report association.';

                        continue;
                    }
                    if (($files[$doc->id] ?? null) !== $doc->checksum) {
                        $hard[] = 'Original source checksum is missing or changed.';
                    }
                    if (! $info['association']) {
                        $hard[] = 'The source names a different client.';
                    }
                    if (! $info['period']['known'] && !($info['period']['scope_confirmed'] ?? false)) {
                        $issues[] = 'Source reporting period is not established.';
                    } elseif ($info['period']['status'] === 'mismatch' && !($info['period']['coverage_accepted'] ?? false)) {
                        $hard[] = 'Source date range conflicts with the reporting period.';
                    } elseif ($info['period']['coverage_accepted'] ?? false) {
                        $issues[] = 'Explicit cross-month source coverage; values are not calendar-month totals.';
                    } elseif ($info['period']['status'] === 'partial') {
                        $issues[] = 'Partial-month source coverage; values do not include every day of the selected month.';
                    }
                    if ($piece) {
                        $method = $piece->content_type === 'ocr_text' || in_array($piece->confidence_status, ['ocr', 'uncertain', 'low']) ? 'ocr' : 'native';
                        if (in_array($piece->confidence_status, ['inferred', 'ambiguous']) && ! $e['verified']) {
                            $issues[] = 'Inferred source structure requires inspection of column and value associations.';
                        }
                        $text = $this->text($piece);
                        $structured = json_decode($piece->structured_json ?? '[]', true) ?? [];
                        if (isset($s->location['row'],$structured['rows'][$s->location['row']])) {
                            $text = json_encode($structured['rows'][$s->location['row']], JSON_UNESCAPED_UNICODE);
                        }
                        if (isset($s->location['start_line'],$s->location['end_line'])) {
                            $lines = array_values(array_filter(array_map('trim', explode("\n", $piece->original_text ?? '')), fn ($v) => $v !== ''));
                            $text = implode("\n", array_slice($lines, max(0, $s->location['start_line'] - 1), $s->location['end_line'] - $s->location['start_line'] + 1));
                        }
                        $evidence[] = ['document_id' => $doc->id, 'extraction_run_id' => $s->extraction_run_id, 'content_id' => $s->content_id, 'location' => $s->location, 'method' => $method, 'original_value' => $s->raw_value];
                        if ($method === 'ocr' && ! $e['verified']) {
                            $issues[] = 'OCR-dependent evidence requires source inspection.';
                        }
                        if (! $e['verified'] && $row->kind === 'report_metrics') {
                            $aliases = ['ga4.total_users' => ['total users'], 'ga4.new_users' => ['new users'], 'ga4.active_users' => ['active users'], 'ga4.users' => ['users'], 'ga4.views' => ['views', 'page views', 'pageviews'], 'ga4.sessions' => ['sessions', 'total sessions'], 'ga4.engaged_sessions' => ['engaged sessions'], 'ga4.engagement_rate' => ['engagement rate'], 'ga4.bounce_rate' => ['bounce rate'], 'ga4.average_engagement_time' => ['average engagement time per active user', 'average engagement time'], 'ga4.organic_search_users' => ['organic search'], 'ga4.average_session_duration' => ['average session duration', 'avg session duration', 'avg. session duration'], 'gsc.clicks' => ['total clicks', 'clicks'], 'gsc.impressions' => ['total impressions', 'impressions'], 'gsc.ctr' => ['site ctr', 'average ctr', 'avg ctr', 'ctr'], 'gsc.average_position' => ['average position', 'avg position'], 'events.reported_total' => ['grand total', 'reported total']];
                            $labels = $aliases[$row->data['metric_key'] ?? ''] ?? [$row->data['label'] ?? ''];
                            $paired = false;
                            foreach ($labels as $label) {
                                if ($label !== '' && preg_match_all('/'.preg_quote($label, '/').'[\s:"\x{2013}\x{2014},\\\\]+([0-9][0-9,:.%]*)/iu', $text, $pairs, PREG_SET_ORDER)) {
                                    foreach ($pairs as $pair) {
                                        $raw = str_replace([',', '%'], '', $pair[1]);
                                        if (str_contains($raw, ':')) {
                                            $parts = explode(':', $raw);
                                            $numeric = 0;
                                            foreach ($parts as $part) {
                                                $numeric = $numeric * 60 + (int) $part;
                                            }
                                        } else {
                                            $numeric = is_numeric($raw) ? (float) $raw : null;
                                        }if ($numeric !== null && (float) $numeric === (float) ($row->data['value'] ?? -1)) {
                                            $paired = true;
                                        }
                                    }
                                }
                            }
                            if (! $paired) {
                                $issues[] = 'Metric label and value are not directly paired at the cited source location.';
                            }
                        }
                        if (! $e['verified']) {
                            $normalized = $this->normalized($text);
                            $fields = match ($row->kind) {
                                'report_metrics' => ['value'],'traffic_channels' => ['channel', 'total_users', 'new_users'],'device_metrics' => ['device', 'clicks', 'impressions'],'query_metrics' => ['query', 'clicks', 'impressions', 'ctr', 'position'],'landing_page_metrics' => ['url', 'clicks', 'impressions', 'ctr'],'keyword_rankings' => ['keyword', 'ranking_label'],'seo_activities' => ['url'],'event_metrics' => ['event_name', 'count'],'ai_visibility_evidence' => ['query'],default => []
                            };
                            foreach ($fields as $field) {
                                $value = $row->data[$field] ?? null;
                                if ($value === null) {
                                    continue;
                                }
                                if (is_numeric($value)) {
                                    $numbers = [];
                                    preg_match_all('/(?<![\d.])-?\d+(?:,\d{3})*(?:\.\d+)?(?![\d.])/u', $text, $matches);
                                    foreach ($matches[0] as $n) {
                                        $numbers[] = (float) str_replace(',', '', $n);
                                    }preg_match_all('/\b(\d+):(\d{2}):(\d{2})\b/', $text, $times, PREG_SET_ORDER);
                                    foreach ($times as $t) {
                                        $numbers[] = (int) $t[1] * 3600 + (int) $t[2] * 60 + (int) $t[3];
                                    }if (! in_array((float) $value, $numbers, true)) {
                                        $issues[] = 'Normalized '.$field.' is not directly supported at the cited source location.';
                                    }
                                } elseif (! str_contains($normalized, $this->normalized((string) $value))) {
                                    $issues[] = 'Normalized '.$field.' is not directly supported at the cited source location.';
                                }
                            }
                        }
                    } elseif (! $s->asset_id) {
                        $hard[] = 'Source text or image is missing.';
                    }
                    if ($s->asset_id) {
                        $a = $c['assets']->get($s->asset_id);
                        if (! $a || ! ($assets[$s->asset_id] ?? null)) {
                            $hard[] = 'Evidence screenshot is missing.';
                        }$evidence[] = ['document_id' => $doc->id, 'asset_id' => $s->asset_id, 'location' => $s->location, 'method' => 'image', 'original_value' => $s->raw_value];
                    }
                }
                if ($row->kind === 'ai_visibility_evidence') {
                    if (! $row->sources->contains(fn ($s) => (bool) $s->asset_id)) {
                        $hard[] = 'AI visibility observation is missing its evidence screenshot.';
                    }
                    if (! $e['verified']) {
                        $issues[] = 'A human must inspect the screenshot before making a brand-appearance claim.';
                    }
                }
                if (in_array($row->confidence, ['inferred', 'ambiguous', 'uncertain']) && ! $e['verified']) {
                    $issues[] = 'Inferred or ambiguous source mapping requires inspection.';
                }
                if ($row->kind === 'keyword_rankings' && ($row->data['ranking_date'] ?? null) && substr($row->data['ranking_date'], 0, 7) !== $p->start_date->format('Y-m')) {
                    $hard[] = 'Keyword observation date is outside this month.';
                }
                if ($row->kind === 'seo_activities' && ($row->data['activity_date'] ?? null) && substr($row->data['activity_date'], 0, 7) !== $p->start_date->format('Y-m')) {
                    $hard[] = 'Activity date is outside this month.';
                }
                if ($row->kind === 'report_metrics' && ($row->data['metric_key'] ?? '') === 'gsc.ctr' && ($row->data['unit'] ?? '') !== 'percent') {
                    $hard[] = 'GSC CTR must preserve percent units.';
                }
                $identity = hash('sha256', json_encode([$row->kind, $row->data, $row->sources->map(fn ($s) => [$s->document_id, $s->extraction_run_id, $s->location])->all()]));
                if (isset($seen[$identity]) && ! $row->manual_override && $row->review_status === 'pending') {
                    $results[$row->id] = ['status' => 'NOT_APPLICABLE', 'issues' => [], 'checks' => ['Redundant representation of '.$seen[$identity].'; original evidence retained, not summed.'], 'evidence' => $evidence];

                    continue;
                }$seen[$identity] = $row->id;
                $checks = ['Source association, current extraction, location and file checksum checked.', 'Original raw values and units retained.', 'Ranking groups, event semantics and source scopes are not inferred.'];
                $issues = array_values(array_unique([...$hard, ...$issues]));
                $status = $hard ? 'INVALID' : ($issues ? 'NEEDS_REVIEW' : ($e['verified'] ? 'HUMAN_VERIFIED' : 'AUTO_VALIDATED'));
                $results[$row->id] = compact('status', 'issues', 'checks', 'evidence');
            }
            // Never sum GA4 channel users or compare differently scoped observations.
            $metrics = $rows->where('kind', 'report_metrics')->where('review_status', '!=', 'rejected');
            foreach ($metrics->groupBy(fn ($r) => $r->data['metric_key'] ?? '') as $key => $group) {
                $values = $group->map(fn ($r) => $r->data['value'] ?? null)->unique();
                if ($values->count() > 1 || $group->map(fn ($r) => $this->semantics($r))->unique()->count() > 1) {
                    foreach ($group as $row) {
                        $results[$row->id]['status'] = 'INVALID';
                        $results[$row->id]['issues'][] = 'Conflicting metric values or scopes remain. Correct or reject the conflicting source; values were not guessed.';
                    }
                }
            }
            $gsc = $metrics->filter(fn ($r) => in_array($r->data['metric_key'] ?? '', ['gsc.clicks', 'gsc.impressions', 'gsc.ctr']));
            foreach ($c['docs'] as $doc) {
                $scoped = $gsc->filter(fn ($r) => $r->sources->count() === 1 && $r->sources->first()->document_id === $doc->id && ($r->data['granularity'] ?? '') === 'reported_period_total')->groupBy(fn ($r) => $r->data['metric_key']);
                if (collect(['gsc.clicks', 'gsc.impressions', 'gsc.ctr'])->every(fn ($k) => isset($scoped[$k]) && $scoped[$k]->count() === 1)) {
                    $click = $scoped['gsc.clicks'][0];
                    $impression = $scoped['gsc.impressions'][0];
                    $ctr = $scoped['gsc.ctr'][0];
                    $sameLocation = count(array_unique(array_map(fn ($r) => json_encode($r->sources->first()->location), [$click, $impression, $ctr]))) === 1;
                    if ($sameLocation && count(array_unique(array_map(fn ($r) => json_encode(array_diff_key(json_decode($this->semantics($r), true), ['unit' => true])), [$click, $impression, $ctr]))) === 1 && ($impression->data['value'] ?? 0) > 0) {
                        $expected = 100 * $click->data['value'] / $impression->data['value'];
                        $difference = abs($expected - $ctr->data['value']);
                        foreach ([$click, $impression, $ctr] as $r) {
                            $results[$r->id]['checks'][] = 'GSC CTR checked against same-document, same-location, same-period total clicks/impressions; rounding tolerance 0.01 percentage points.';
                            if ($difference > 0.010001) {
                                $results[$r->id]['status'] = 'INVALID';
                                $results[$r->id]['issues'][] = 'GSC CTR does not reconcile with same-scope clicks and impressions.';
                            }
                        }
                    }
                }
            }
            $scopeKey = function ($r) {
                $s = $r->sources->count() === 1 ? $r->sources->first() : null;

                return $s ? json_encode([$s->document_id, $s->extraction_run_id, $s->location, $r->data['granularity'] ?? null, array_diff_key(json_decode($this->semantics($r), true), ['unit' => true])]) : null;
            };
            foreach ($metrics->groupBy($scopeKey) as $scope => $group) {
                if (! $scope) {
                    continue;
                }$keys = $group->groupBy(fn ($r) => $r->data['metric_key'] ?? '');
                foreach ([['ga4.new_users', 'ga4.total_users'], ['gsc.clicks', 'gsc.impressions'], ['ga4.engaged_sessions', 'ga4.sessions']] as [$part,$whole]) {
                    if (isset($keys[$part],$keys[$whole]) && $keys[$part]->count() === 1 && $keys[$whole]->count() === 1 && ($keys[$part][0]->data['granularity'] ?? null) === 'reported_period_total' && $keys[$part][0]->data['value'] > $keys[$whole][0]->data['value']) {
                        foreach ([$keys[$part][0], $keys[$whole][0]] as $r) {
                            $results[$r->id]['status'] = 'INVALID';
                            $results[$r->id]['issues'][] = 'Same-scope '.$part.' exceeds '.$whole.'. Source values are retained.';
                        }
                    }
                }
            }
            // Event totals reconcile only within the same document/page; traffic users are never summed.
            foreach ($metrics->filter(fn ($r) => ($r->data['metric_key'] ?? '') === 'events.reported_total') as $total) {
                $s = $total->sources->count() === 1 ? $total->sources->first() : null;
                if (! $s) {
                    continue;
                }$events = $rows->where('kind', 'event_metrics')->where('review_status', '!=', 'rejected')->filter(fn ($r) => $results[$r->id]['status'] !== 'NOT_APPLICABLE')->filter(function ($r) use ($s) {
                    $ref = $r->sources->count() === 1 ? $r->sources->first() : null;

                    return $ref && $ref->document_id === $s->document_id && $ref->extraction_run_id === $s->extraction_run_id && ($ref->location['page'] ?? null) === ($s->location['page'] ?? null) && isset($s->location['page']);
                });
                if ($events->isNotEmpty() && $events->sum(fn ($r) => $r->data['count'] ?? 0) != $total->data['value']) {
                    $results[$total->id]['status'] = 'INVALID';
                    $results[$total->id]['issues'][] = 'Same-source event observations do not reconcile with the reported event total; inspect extraction completeness.';
                }
            }
            $accepted = $rows->filter(fn ($r) => in_array($results[$r->id]['status'], ['AUTO_VALIDATED', 'HUMAN_VERIFIED']));
            $missingMetrics = array_values(array_diff(config('delivery.required_metrics'), $accepted->where('kind', 'report_metrics')->map(fn ($r) => $r->data['metric_key'])->all()));
            $missingSections = array_values(array_diff(config('delivery.required_sections'), $accepted->map(fn ($r) => ReportDelivery::SECTION_MAP[$r->section_key] ?? $r->section_key)->unique()->all()));
            $counts = array_fill_keys(self::STATES, 0);
            foreach ($results as $r) {
                $counts[$r['status']]++;
            }
            foreach ($documentIssues as &$issue) {
                $issue['document_name'] = $docInfo[$issue['document_id']]['filename'] ?? 'Uploaded source';
                $issue['source_url'] = $issue['document_id'] ? '/projects/'.$p->project_id.'/reports/'.$p->id.'/documents/'.$issue['document_id'] : null;
            }unset($issue);
            $summary = ['source_periods' => collect($docInfo)->map(fn ($info, $id) => ['document_id' => $id, 'document' => $info['filename'], ...$info['period']])->values()->all(), 'counts' => $counts, 'documents' => $c['docs']->count(), 'successfully_extracted' => $c['docs']->filter(fn ($d) => in_array($d->extraction_status, ['extracted', 'partially_extracted', 'needs_review']))->count(), 'document_issues' => $documentIssues, 'missing_metrics' => $missingMetrics, 'missing_sections' => $missingSections, 'evidence_coverage' => ['supported' => $accepted->count(), 'business_records' => $rows->where('kind', '!=', 'unclassified')->where('review_status', '!=', 'rejected')->count()], 'material_issues' => $counts['NEEDS_REVIEW'] + $counts['INVALID'] + count($documentIssues), 'data_ready' => $c['current'] && ! $p->requires_regeneration && $counts['NEEDS_REVIEW'] === 0 && $counts['INVALID'] === 0 && ! $documentIssues && ! $missingMetrics && ! $missingSections];
            $id = (string) Str::ulid();
            DB::table('report_validation_runs')->insert(['id' => $id, 'reporting_period_id' => $p->id, 'normalization_run_id' => $c['run']?->id, 'actor_id' => $actor->id, 'rules_version' => self::VERSION, 'fingerprint' => $fingerprint, 'before_summary' => json_encode($previous['summary'] ?? []), 'summary' => json_encode($summary), 'created_at' => now(), 'updated_at' => now()]);
            foreach (array_chunk($results, 100, true) as $chunk) {
                DB::table('record_validation_results')->insert(collect($chunk)->map(fn ($v, $record) => ['id' => (string) Str::ulid(), 'validation_run_id' => $id, 'normalized_record_id' => $record, 'status' => $v['status'], 'issues' => json_encode($v['issues']), 'checks' => json_encode($v['checks']), 'evidence' => json_encode($v['evidence'])])->values()->all());
            }

            return $this->current($p, $in);
        }, 5);
    }
}
