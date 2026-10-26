<?php

namespace App\Services;

use App\Models\NormalizedRecord;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/** Frozen export-only metadata and deterministic presentation; never approves or edits reporting data. */
class ReportPdfPresentation
{
    public const TITLES = ['data_limitations' => 'Data Limitations', 'executive_summary' => 'Executive Summary', 'overview' => 'Overview KPI Metrics', 'website_traffic' => 'Website Analytics', 'organic_search' => 'Google Search Console', 'keywords' => 'Keyword Rankings', 'seo_work' => 'SEO Activities', 'ai_visibility' => 'AI Visibility Evidence', 'key_events' => 'Key Events', 'sources' => 'Sources and Methodology'];

    public function capture(ReportingPeriod $p, User $user, string $profile): array
    {
        $d = app(ReportDelivery::class)->snapshot($p, $user, true);
        if ($profile !== 'internal_audit') {
            return $d;
        }
        $rows = NormalizedRecord::where('reporting_period_id', $p->id)->where('project_id', $p->project_id)->with(['sources', 'corrections'])->orderBy('id')->get();
        $ids = $rows->pluck('id');
        $runs = DB::table('normalization_runs')->where('reporting_period_id', $p->id)->orderBy('created_at')->get();
        $d['_audit'] = [
            'captured_at' => now()->toIso8601String(),
            'registry' => $rows->map(fn ($r) => ['id' => $r->id, 'kind' => $r->kind, 'section' => $r->section_key, 'active' => $r->active, 'review_status' => $r->review_status, 'revision' => $r->revision, 'normalization_run_id' => $r->normalization_run_id, 'source_key' => $r->source_key, 'source_hash' => $r->source_hash, 'data' => $r->data, 'sources' => $r->sources->toArray(), 'corrections' => $r->corrections->toArray()])->all(),
            'verifications' => DB::table('record_verifications')->whereIn('normalized_record_id', $ids)->orderBy('created_at')->get()->toArray(),
            'warnings' => DB::table('validation_warnings')->whereIn('normalization_run_id', $runs->pluck('id'))->orderBy('id')->get()->toArray(),
            'summary_revisions' => DB::table('report_summary_revisions')->where('reporting_period_id', $p->id)->orderBy('revision')->get()->toArray(),
            'summary_approvals' => DB::table('report_summary_approvals')->whereIn('summary_revision_id', DB::table('report_summary_revisions')->where('reporting_period_id', $p->id)->select('id'))->get()->toArray(),
            'report_history' => DB::table('project_activity_logs')->where('reporting_period_id', $p->id)->orderBy('created_at')->get()->toArray(),
            'normalization_runs' => $runs->toArray(),
            'documents' => $p->documents()->withTrashed()->get()->map(fn ($doc) => $doc->only(['id', 'original_filename', 'checksum', 'current_run_id', 'extraction_status', 'deleted_at']))->all(),
        ];

        return $d;
    }

    public function build(array $d, array $sections, string $profile): array
    {
        $sources = [];
        $lookup = [];
        $records = [];
        $urls = array_count_values(array_map(fn ($r) => trim((string) ($r['data']['url'] ?? '')), array_filter($d['records'], fn ($r) => $r['kind'] === 'seo_activities')));
        foreach ($d['records'] as $i => $r) {
            $refs = [];
            foreach ($r['sources'] as $s) {
                $hash = hash('sha256', json_encode($s));
                if (! isset($lookup[$hash])) {
                    $key = 'S'.str_pad((string) (count($sources) + 1), 3, '0', STR_PAD_LEFT);
                    $lookup[$hash] = $key;
                    $sources[$key] = $s;
                }
                $refs[] = $lookup[$hash];
            }
            $r['key'] = 'R'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            $r['refs'] = array_values(array_unique($refs));
            $r['duplicate'] = $r['kind'] === 'seo_activities' && trim((string) ($r['data']['url'] ?? '')) !== '' && ($urls[trim((string) ($r['data']['url'] ?? ''))] ?? 0) > 1;
            $records[] = $r;
        }
        $activities = array_values(array_filter($records, fn ($r) => $r['kind'] === 'seo_activities'));
        $activityCounts = [];
        foreach ($activities as $r) {
            $key = $r['data']['category'] ?? 'Not reported';
            $activityCounts[$key] = ($activityCounts[$key] ?? 0) + 1;
        }
        $events = array_values(array_filter($records, fn ($r) => $r['kind'] === 'event_metrics'));
        $eventRows = array_map(fn ($r) => ['label' => $r['data']['event_name'] ?? 'Not reported', 'value' => $r['data']['count'] ?? null], $events);
        $warnings = array_values(array_filter($d['warnings'] ?? [], fn ($w) => $w['blocking'] && ! $w['resolved_for_included_records']));
        $warningGroups = [];
        foreach ($warnings as $w) {
            $warningGroups[$w['code']] = ($warningGroups[$w['code']] ?? 0) + 1;
        }

        return ['records' => $records, 'sources' => $sources, 'sections' => $sections, 'titles' => self::TITLES, 'audit' => ($d['draft'] ?? true) && $profile === 'internal_audit', 'profile' => $profile,
            'summary_state' => $d['summary_state'] ?? (($d['draft'] ?? true) ? 'unknown' : 'approved'),
            'provisional' => count(array_filter($records, fn ($r) => $r['provisional'] ?? false)),
            'warning_count' => count($warnings), 'warning_groups' => $warningGroups, 'activity_counts' => $activityCounts, 'activity_total' => count($activities), 'duplicate_observations' => count(array_filter($activities, fn ($r) => $r['duplicate'])), 'event_rows' => $eventRows, 'event_total' => array_sum(array_column($eventRows, 'value'))];
    }

    public function number(mixed $value): string
    {
        if ($value === null) {
            return 'Not reported';
        }
        if (! is_numeric($value)) {
            return (string) $value;
        }
        $text = (string) $value;
        // Do not round the normalized observation to a made-up display precision.
        if (stripos($text, 'e') !== false) {
            return $text;
        }
        $parts = explode('.', $text, 2);

        return number_format((float) $value, isset($parts[1]) ? strlen($parts[1]) : 0);
    }

    public function url(mixed $value): HtmlString
    {
        if ($value === null || $value === '') {
            return new HtmlString('Not reported');
        }
        // Escape before inserting zero-width HTML break opportunities; never render source markup.
        $parts = preg_split('~(?<=[/?.&=_\-])~u', (string) $value);

        return new HtmlString(implode('<wbr>', array_map(fn ($part) => e($part), $parts)));
    }

    public function json(mixed $value, bool $pretty = true): string
    {
        return json_encode($value, ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
