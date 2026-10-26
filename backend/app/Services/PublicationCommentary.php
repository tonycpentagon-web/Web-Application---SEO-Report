<?php

namespace App\Services;

/** Factual narrative calculated from the exact, deduplicated publication rows. */
class PublicationCommentary
{
    public function sections(array $snapshot): array
    {
        $rows = collect($snapshot['records']);
        $metric = function (string $key) use ($snapshot): string {
            $k = collect($snapshot['kpis'])->firstWhere('metric_key', $key);
            return !$k || ($k['conflict'] ?? false) || $k['value'] === null ? 'not reported' : number_format($k['value'], $k['unit'] === 'percent' ? 2 : 0).($k['unit'] === 'percent' ? '%' : '');
        };
        $counts = fn ($kind, $field) => $rows->where('kind', $kind)->countBy(fn ($r) => $r['data'][$field] ?? 'Not reported')->map(fn ($n, $label) => str_replace('_', ' ', $label).': '.$n)->implode('; ');
        $keywords = $rows->where('kind', 'keyword_rankings')->count();
        $activities = $rows->where('kind', 'seo_activities')->count();
        return [
            'website_traffic' => 'The source reports '.$metric('ga4.total_users').' users, '.$metric('ga4.new_users').' new users and '.$metric('ga4.views').' views. Sessions: '.$metric('ga4.sessions').'. Channel user counts may overlap and must not be added to the unique-user total.',
            'organic_search' => 'Organic Search accounts for '.$metric('ga4.organic_search_users').' users. Search Console reports '.$metric('gsc.clicks').' clicks and '.$metric('gsc.impressions').' impressions, with a reported CTR of '.$metric('gsc.ctr').'. Clicks and users measure different things.',
            'keywords' => $keywords.' distinct keyword observations: '.$counts('keyword_rankings', 'ranking_label').'. Repeated identical observations are shown once with their source locations retained. Page labels describe result pages, not exact ranking positions.',
            'seo_work' => $activities.' distinct listed activities: '.$counts('seo_activities', 'category').'. Repeated identical submissions are counted once. Listed submissions do not establish that backlinks are live or indexed.',
            'ai_visibility' => $rows->where('kind', 'ai_visibility_evidence')->count().' original screenshots document the listed queries. Brand appearance remains unverified; these images do not establish an AI visibility score or traffic.',
            'key_events' => $rows->where('kind', 'event_metrics')->count().' event types are listed. Source-reported total: '.$metric('events.reported_total').'. These events are not a count of verified leads.',
            'data_limitations' => 'Printed source coverage and uncertainty remain disclosed alongside the report. Missing days and missing metrics are not estimated. Some keyword associations, submission links and image-derived values need source inspection. No month-over-month comparison is available in this publication.',
        ];
    }
}
