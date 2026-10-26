@php
$schemas = [
 'report_metrics'=>['label'=>'Metric','value'=>'Reported value','unit'=>'Unit'],
 'traffic_channels'=>['channel'=>'Channel','total_users'=>'Users','new_users'=>'New users'],
 'device_metrics'=>['device'=>'Device','clicks'=>'Clicks','impressions'=>'Impressions'],
 'query_metrics'=>['query'=>'Search query','clicks'=>'Clicks','impressions'=>'Impressions','ctr'=>'CTR (%)','position'=>'Average position'],
 'landing_page_metrics'=>['url'=>'Landing page','clicks'=>'Clicks','impressions'=>'Impressions','ctr'=>'CTR (%)'],
 'keyword_rankings'=>['keyword'=>'Keyword','ranking_label'=>'Reported ranking','category'=>'Category','ranking_date'=>'Date','search_location'=>'Location'],
 'seo_activities'=>['category'=>'Activity category','url'=>'Reported URL'],
 'event_metrics'=>['event_name'=>'Event category','count'=>'Reported count'],
];
$cols = $schemas[$kind];
@endphp
<table class="report-table">
    <caption>{{ $tableTitle }}</caption>
    <colgroup>
        @if($kind === 'keyword_rankings')
            <col style="width:30%"><col style="width:15%"><col style="width:12%"><col style="width:12%"><col style="width:17%"><col style="width:14%">
        @elseif($kind === 'seo_activities')
            <col style="width:19%"><col style="width:65%"><col style="width:16%">
        @elseif($kind === 'landing_page_metrics')
            <col style="width:45%"><col style="width:12%"><col style="width:14%"><col style="width:12%"><col style="width:17%">
        @endif
    </colgroup>
    <thead><tr>
        @foreach($cols as $heading)<th>{{ $heading }}</th>@endforeach
        <th>Evidence / state</th>
    </tr></thead>
    <tbody>
    @foreach($tableRows as $r)
        <tr>
        @foreach($cols as $field=>$heading)
            <td class="{{ $field === 'url' ? 'url' : '' }}">
            @if($field === 'url')
                {{ $presenter->url($r['data'][$field] ?? null) }}
            @elseif(in_array($field, ['value','clicks','impressions','ctr','count','total_users','new_users']))
                {{ $presenter->number($r['data'][$field] ?? null) }}
            @elseif($field === 'category')
                {{ ucwords(str_replace('_', ' ', $r['data'][$field] ?? 'Not reported')) }}
            @else
                {{ $r['data'][$field] ?? 'Not reported' }}
            @endif
            @if($field === 'keyword' && !empty($r['data']['alternative_labels']))<small>Other source wording (association uncertain): {{ implode('; ', $r['data']['alternative_labels']) }}</small>@endif
            @if($field === 'url' && $r['duplicate'])<div class="duplicate">Suspected duplicate — same reported URL; observation retained.</div>@endif
            </td>
        @endforeach
            <td class="ref">{{ $r['key'] }} · {{ implode(', ', $r['refs']) ?: 'Source not available' }}
            @if($r['provisional'] ?? false)<span class="row-state">Provisional / uncertain source observation</span>@elseif($d['draft'])<span class="row-state">Source-backed observation</span>@endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
