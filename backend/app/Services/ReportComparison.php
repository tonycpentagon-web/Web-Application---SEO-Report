<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/** Fail-closed source coverage and definition checks; never infer missing history. */
class ReportComparison
{
    private const METRICS = ['ga4.total_users', 'gsc.clicks', 'gsc.impressions', 'gsc.ctr', 'events.reported_total'];

    private function coverage(array $record, array $report): bool
    {
        $data = $record['data'];
        $periods = ! empty($data['source_start_date']) && ! empty($data['source_end_date']) ? [['start' => $data['source_start_date'], 'end' => $data['source_end_date']]] : array_merge(...array_map(fn ($s) => $s['source_period'] ?? [], $record['sources'] ?? []));
        $start = substr($report['start_date'], 0, 10);
        $end = substr($report['end_date'], 0, 10);
        try {
            $date = CarbonImmutable::parse($start);
            if ($start !== $date->startOfMonth()->toDateString() || $end !== $date->endOfMonth()->toDateString()) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return count($periods) > 0 && collect($periods)->every(fn ($p) => ($p['start'] ?? null) === $start && ($p['end'] ?? null) === $end);
    }

    public function compare(array $current, array $prior): array
    {
        $metrics = [];
        foreach (self::METRICS as $key) {
            $a = collect($current['kpis'])->firstWhere('metric_key', $key);
            $b = collect($prior['kpis'])->firstWhere('metric_key', $key);
            $ar = collect($current['records'])->filter(fn ($r) => $r['kind'] === 'report_metrics' && ($r['data']['metric_key'] ?? null) === $key);
            $br = collect($prior['records'])->filter(fn ($r) => $r['kind'] === 'report_metrics' && ($r['data']['metric_key'] ?? null) === $key);
            $reason = null;
            if (! $a || ! $b || ! is_numeric($a['value']) || ! is_numeric($b['value']) || ! empty($a['conflict']) || ! empty($b['conflict'])) {
                $reason = 'Missing or conflicting source values.';
            } elseif ($a['unit'] !== $b['unit'] || $ar->isEmpty() || $br->isEmpty()) {
                $reason = 'Metric definitions or units are unavailable or incompatible.';
            } else {
                $definitions = $ar->merge($br)->map(fn ($r) => [$r['data']['definition'] ?? null, $r['data']['granularity'] ?? null])->unique(fn ($d) => json_encode($d));
                if ($definitions->count() !== 1 || empty($definitions->first()[0]) || empty($definitions->first()[1])) {
                    $reason = 'Metric definitions are unavailable or incompatible.';
                } elseif (! $ar->every(fn ($r) => $this->coverage($r, $current['report'])) || ! $br->every(fn ($r) => $this->coverage($r, $prior['report']))) {
                    $reason = 'Explicit source coverage must match both complete calendar reporting months.';
                }
            }
            $metrics[] = ['metric_key' => $key, 'label' => $a['label'] ?? $b['label'] ?? $key, 'unit' => $a['unit'] ?? $b['unit'] ?? '', 'current' => $a['value'] ?? null, 'prior' => $b['value'] ?? null, 'comparable' => $reason === null, 'difference' => $reason === null ? $a['value'] - $b['value'] : null, 'difference_unit' => ($a['unit'] ?? '') === 'percent' ? 'percentage points' : ($a['unit'] ?? ''), 'reason' => $reason];
        }
        $priorKeywords = collect($prior['records'])->where('kind', 'keyword_rankings')->groupBy(fn ($r) => $this->keywordKey($r));
        $currentKeywords = collect($current['records'])->where('kind', 'keyword_rankings');
        $keywords = [];
        foreach ($currentKeywords as $record) {
            $data = $record['data'];
            $key = $this->keywordKey($record);
            $matches = $priorKeywords->get($key, collect());
            $previous = $matches->first();
            $reason = null;
            if (empty($data['search_location']) || empty($data['category']) || empty($data['ranking_date']) || ! $previous || $matches->count() !== 1 || $currentKeywords->filter(fn ($r) => $this->keywordKey($r) === $key)->count() !== 1) {
                $reason = 'Missing date/location/category, unmatched keyword, or ambiguous duplicate observations.';
            }
            $old = $previous['data'] ?? [];
            if (! $reason && (empty($old['ranking_date']) || ! $this->within($data['ranking_date'], $current['report']) || ! $this->within($old['ranking_date'], $prior['report']))) {
                $reason = 'Ranking observation dates must fall within their reporting months.';
            }
            [$kind,$value] = $this->rank($data);
            [$oldKind,$oldValue] = $this->rank($old);
            if (! $reason && (! $kind || $kind !== $oldKind)) {
                $reason = 'Ranking label kinds are unavailable or incompatible.';
            }
            $keywords[] = ['keyword' => $data['keyword'] ?? '', 'location' => $data['search_location'] ?? null, 'current' => $data['ranking_label'] ?? null, 'prior' => $old['ranking_label'] ?? null, 'comparable' => $reason === null, 'difference' => $reason === null ? $value - $oldValue : null, 'difference_unit' => $kind === 'page' ? 'page groups (not positions)' : 'positions', 'reason' => $reason];
        }

        return ['provisional' => ! empty($current['draft']) || ! empty($prior['draft']), 'current' => $current['report'], 'prior' => $prior['report'], 'metrics' => $metrics, 'keywords' => $keywords, 'methodology' => 'Differences compare complete calendar-month source totals, not equal-duration daily rates. CTR changes are percentage points. Negative ranking deltas mean a lower reported page group or exact position; page groups never become exact positions. Missing or ambiguous evidence is not compared.'];
    }

    private function keywordKey(array $r): string
    {
        return json_encode(array_map(fn ($k) => mb_strtolower(trim((string) ($r['data'][$k] ?? ''))), ['keyword', 'search_location', 'category']));
    }

    private function within(string $date, array $report): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) && $date >= substr($report['start_date'], 0, 10) && $date <= substr($report['end_date'], 0, 10);
    }

    private function rank(array $d): array
    {
        $label = trim((string) ($d['ranking_label'] ?? ''));
        if (preg_match('/^([1-9]\d*)(?:st|nd|rd|th) Page$/iD', $label, $m)) {
            return ['page', (int) $m[1]];
        }
        if (($d['group'] ?? '') === 'exact_position' && is_numeric($d['exact_position'] ?? null)) {
            return ['exact', (int) $d['exact_position']];
        }

        return [null, null];
    }
}
