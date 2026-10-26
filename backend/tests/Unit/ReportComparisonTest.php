<?php

namespace Tests\Unit;

use App\Services\ReportComparison;
use PHPUnit\Framework\TestCase;

class ReportComparisonTest extends TestCase
{
    private function snapshot(string $month, float $users, float $ctr): array
    {
        $start = "2026-$month-01";
        $end = $month === '08' ? '2026-08-31' : '2026-09-30';
        $records = [];
        $kpis = [];
        foreach (['ga4.total_users' => [$users, 'count', 'ga4'], 'gsc.ctr' => [$ctr, 'percent', 'gsc']] as $key => [$value,$unit,$definition]) {
            $data = ['metric_key' => $key, 'value' => $value, 'unit' => $unit, 'definition' => $definition, 'granularity' => 'reported_period_total'];
            $records[] = ['kind' => 'report_metrics', 'data' => $data, 'sources' => [['source_period' => [['start' => $start, 'end' => $end]]]]];
            $kpis[] = ['metric_key' => $key, 'label' => $key, 'value' => $value, 'unit' => $unit];
        }
        $records[] = ['kind' => 'keyword_rankings', 'data' => ['keyword' => 'Synthetic phrase', 'category' => 'primary', 'search_location' => 'Dubai', 'ranking_date' => "2026-$month-12", 'ranking_label' => $month === '08' ? '2nd Page' : '1st Page', 'group' => $month === '08' ? 'page_2' : 'page_1', 'page_group' => $month === '08' ? 2 : 1, 'exact_position' => null]];

        return ['report' => ['title' => $month, 'start_date' => $start, 'end_date' => $end], 'records' => $records, 'kpis' => $kpis];
    }

    public function test_complete_months_compare_totals_ctr_points_and_page_groups_only(): void
    {
        $r = (new ReportComparison)->compare($this->snapshot('09', 120, 1.7), $this->snapshot('08', 100, 1.5));
        $this->assertSame(20.0, $r['metrics'][0]['difference']);
        $this->assertEqualsWithDelta(.2, $r['metrics'][3]['difference'], .00001);
        $this->assertSame('percentage points', $r['metrics'][3]['difference_unit']);
        $this->assertSame(-1, $r['keywords'][0]['difference']);
        $this->assertSame('page groups (not positions)', $r['keywords'][0]['difference_unit']);
    }

    public function test_partial_missing_ambiguous_dates_and_incompatible_definitions_fail_closed(): void
    {
        $a = $this->snapshot('09', 120, 1.7);
        $b = $this->snapshot('08', 100, 1.5);
        $a['records'][0]['sources'][0]['source_period'][0]['end'] = '2026-09-29';
        $a['records'][1]['data']['definition'] = 'different_ctr_definition';
        $a['records'][2]['data']['search_location'] = null;
        $r = (new ReportComparison)->compare($a, $b);
        $this->assertFalse($r['metrics'][0]['comparable']);
        $this->assertNull($r['metrics'][0]['difference']);
        $this->assertFalse($r['metrics'][3]['comparable']);
        $this->assertFalse($r['keywords'][0]['comparable']);
        $a = $this->snapshot('09', 120, 1.7);
        $a['records'][2]['data'] = array_merge($a['records'][2]['data'], ['ranking_label' => '#1', 'group' => 'exact_position', 'exact_position' => 1]);
        $this->assertFalse((new ReportComparison)->compare($a, $b)['keywords'][0]['comparable']);
        $a['records'][2]['data'] = $b['records'][2]['data'];
        $a['records'][2]['data']['ranking_date'] = '2026-09-12';
        $a['records'][] = $a['records'][2];
        $r = (new ReportComparison)->compare($a, $b);
        $this->assertFalse($r['keywords'][0]['comparable']);
    }
}
