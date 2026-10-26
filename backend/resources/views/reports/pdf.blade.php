<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $d['report']['title'] }} — Pentagon</title>
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'">
@php
$presenter = app(\App\Services\ReportPdfPresentation::class);
$profile = $profile ?? 'client_preview';
$v = $presenter->build($d, $sections, $profile);
$displaySections=$sections;
$sourcePeriods=collect($d['source_periods']??[]);
$uncertainDates=$sourcePeriods->contains(fn($p)=>$p['status']==='uncertain');
$coverRanges=$sourcePeriods->reject(fn($p)=>$p['status']==='uncertain')->flatMap(fn($p)=>$p['ranges'])->unique(fn($r)=>$r['start'].'|'.$r['end'])->sortBy(fn($r)=>$r['start'].'|'.$r['end'])->values();
$coverPeriodLabel=$uncertainDates?($coverRanges->isEmpty()?'Reporting month (source dates unconfirmed)':'Source periods (some dates unconfirmed)'):($coverRanges->count()>1?'Source periods':'Reporting period');
$coverPeriodText=$coverRanges->isEmpty()&&$uncertainDates?\Carbon\Carbon::parse($d['report']['start_date'])->format('F Y'):($coverRanges->isEmpty()?collect([['start'=>$d['report']['start_date'],'end'=>$d['report']['end_date']]]):$coverRanges)->map(fn($r)=>\Carbon\Carbon::parse($r['start'])->format('j M Y').' – '.\Carbon\Carbon::parse($r['end'])->format('j M Y'))->implode('; ');

if(!$v['audit']) {
    $v['titles']=array_replace($v['titles'], ['overview'=>'Conversion events','website_traffic'=>'Website traffic & analytics','organic_search'=>'Organic search performance','keywords'=>'Keyword rankings','seo_work'=>'Off-page backlink activity','ai_visibility'=>'AI visibility evidence','key_events'=>'Key events','executive_summary'=>'Report summary','sources'=>'Sources & reporting notes']);
    $displaySections=array_values(array_intersect(['overview','website_traffic','organic_search','keywords','seo_work','ai_visibility'],$sections));
    if(in_array('key_events',$sections)&&!in_array('overview',$displaySections))array_unshift($displaySections,'overview');
}
@endphp
@include('reports.partials.style')
@if(!$v['audit'])
<style>
.cover-kicker{margin-top:24mm}.cover-line{margin-top:12mm}.cover-bottom{margin-top:8mm}.section-evidence{font-size:8px;line-height:1.5;overflow-wrap:anywhere}.section-evidence li{margin:4px 0}
h1{font-weight:bold!important}h1,h2,h3,h4,h5,h6{font-family:Inter, "Segoe UI", Arial, sans-serif;font-weight:normal;color:#203444}h2{font-size:24px}.brand{font:bold italic 30px Georgia,serif;letter-spacing:-1px;color:#203444}.brand span{color:inherit}.brand-note{letter-spacing:1.8px;font-size:7px}.cover{border-top-color:#00ade2}.cover .client,.eyebrow{color:#007ea5}.cover-line{background:linear-gradient(90deg,#00ade2 35%,#e2edf2 35%)}.kpi{background:white;border-top-color:#00ade2}.kpi-label{text-transform:uppercase;font-size:8px;letter-spacing:.7px}.kpi-value{color:#203444}.section-head{border-bottom-color:#00ade2}.report-table th{background:#00ade2;color:#173446}.chart{background:#f6fafc}.fact{border-left-color:#00ade2}.notice.neutral{background:#edf7fb;border-color:#d2e9f2}
.evidence-page{break-before:auto;break-inside:avoid;margin-top:18px}.evidence-frame{margin:8px 0}.evidence-image{max-height:120mm}.evidence-caption{font-size:8px;line-height:1.5}.evidence-head h3{margin:6px 0}
</style>
@endif
</head><body>
@if($d['draft'])<div class="watermark">DRAFT</div>@endif
<div class="cover">
    <div class="cover-grid"><div><div class="brand">@if($v['audit'])PENTA<span>GON</span>@else Pentagon @endif</div><div class="brand-note">INFORMATION TECHNOLOGY LLC</div></div>
    @if($d['project']['logo'])<img class="cover-logo" src="{{ $d['project']['logo'] }}" alt="Client logo">@endif
    </div>
    <p class="eyebrow cover-kicker">INSIGHT FLOW / SEO PERFORMANCE REPORT</p>
    <h1>SEO Performance Report <span style="color:#00ade2">·</span><br>{{ \Carbon\Carbon::parse($d['report']['start_date'])->format('F Y') }}</h1>@if($d['report']['title'] !== \Carbon\Carbon::parse($d['report']['start_date'])->format('F Y'))<p class="section-note">{{ $d['report']['title'] }}</p>@endif
    <div class="client">{{ $d['project']['client_name'] ?? $d['project']['name'] }}</div>
    <p class="website">{{ $d['project']['website'] }}</p>
    <div class="cover-line"></div>
    <dl class="cover-meta">
        <div><dt>{{ $v['audit'] ? 'Reporting period' : $coverPeriodLabel }}</dt><dd>{{ $v['audit'] ? $d['report']['start_date'].' – '.$d['report']['end_date'] : $coverPeriodText }}</dd></div>
        <div><dt>Report status</dt><dd>{{ $d['draft'] ? 'DRAFT / Internal review' : 'Published / Version '.($d['publication_version'] ?? '') }}</dd></div>
        <div><dt>Export profile</dt><dd>{{ $v['audit'] ? 'Internal Audit Export' : ($d['draft'] ? 'Client-style Draft Preview' : 'Published Client Report') }}</dd></div>
        <div><dt>Generated</dt><dd>{{ \Carbon\Carbon::parse($d['generated_at'])->format('d M Y · H:i T') }}</dd></div>
    </dl>
    @if($v['audit'] && !empty($d['source_periods']))
    @php $limited=collect($d['source_periods'])->filter(fn($source)=>in_array($source['status'],['partial','uncertain','mismatch'])||($source['coverage_accepted']??false)); @endphp
    @if($limited->isNotEmpty())<div class="notice"><strong>Actual source coverage</strong><p>Totals retain their printed coverage. Cross-month source totals are not calendar-month totals. Missing days are not estimated.</p><ul>@foreach($limited as $source)<li>{{ $source['document'] }}: @foreach($source['ranges'] as $range){{ $range['start'] }} to {{ $range['end'] }}; @endforeach @if(!empty($source['uncovered_dates']))Not covered by this range: {{ implode(', ', $source['uncovered_dates']) }}.@endif @if($source['status']==='uncertain') Date extraction is uncertain; employee assignment does not verify OCR values.@endif</li>@endforeach</ul></div>@endif
    @endif
    @if($v['audit'] && !empty($d['disclosures']))<div class="notice"><strong>Source limitations</strong><p>Some observations remain provisional. See the contextual evidence and report limitations for extraction details. Missing figures are not estimated.</p></div>@endif
    <div class="cover-bottom">
    @if($d['draft'])
        <span class="pill pending">PRIVATE DRAFT</span>
        <p style="margin-top:8px"><strong>Internal Review – Not for Client Distribution</strong></p>
        <p class="section-note">{{ $v['provisional'] }} provisional business observations. {{ $v['warning_count'] }} unresolved blocking warnings. Values retain their source reporting periods. This export does not approve or publish the report.</p>
    @else
        <span class="pill">PUBLISHED SNAPSHOT</span><p class="section-note">Source-backed reporting values and commentary captured at publication. Sources and methodology are included.</p>
    @endif
    <p class="eyebrow" style="margin-top:15px">EVIDENCE-LED REPORTING / PENTAGON</p>
    </div>
</div>
@foreach($displaySections as $key)

@if($key === 'sources')
    @include('reports.partials.sources')
@elseif($key === 'ai_visibility')
    @php $evidence = array_values(array_filter($v['records'], fn($r) => $r['kind'] === 'ai_visibility_evidence')); @endphp
    @forelse($evidence as $r)
    <article class="evidence-page">
        @if($v['audit'] || $loop->first)
        <div class="section-head"><div><p class="eyebrow">SOURCE EVIDENCE / {{ $loop->iteration }} OF {{ count($evidence) }}</p><h2>{{ $v['titles']['ai_visibility'] }}</h2></div><span class="index">{{ str_pad((string)(array_search('ai_visibility',$displaySections)+1),2,'0',STR_PAD_LEFT) }}</span></div>
        @else<p class="eyebrow">AI VISIBILITY / {{ $loop->iteration }} OF {{ count($evidence) }}</p>@endif
        <div class="evidence-head"><h3>{{ $r['data']['query'] ?? 'Query not reported' }}</h3>
        <p>{{ $r['data']['platform'] ?? 'Platform not reported' }} ·
        @if($r['provisional'] ?? false)<span class="pill pending">Provisional / evidence not verified</span>@else<span class="pill">Source evidence</span>@endif
        </p></div>
        @if($loop->first && !empty($d['summary']['ai_visibility']))
            <p class="narrative">{{ $d['summary']['ai_visibility'] }}</p><p class="section-note">Commentary: {{ str_replace('_',' ', $v['summary_state']) }}.</p>
        @endif
        <div class="evidence-frame">
        @if($r['image'])<img class="evidence-image" src="{{ $r['image'] }}" alt="Source screenshot for {{ $r['data']['query'] ?? 'AI query' }}">@else<p class="empty">Source screenshot is unavailable. No image or visibility result has been inferred.</p>@endif
        </div>
        <p class="evidence-caption"><strong>Recorded brand-appearance decision:</strong> {{ $r['data']['brand_appearance'] ?? 'Unreviewed' }}.
        @if($r['provisional'] ?? false)This observation is not a verified visibility claim. A screenshot alone does not establish rankings, visibility share or traffic.@endif
        </p>
        <p class="evidence-caption"><strong>{{ $r['key'] }} · {{ implode(', ', $r['refs']) }}</strong> / @foreach(collect($r['sources'])->unique(fn($source)=>json_encode($source)) as $source){{ $source['document'] }} · {{ json_encode($source['location'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }} @endforeach</p>
    </article>
    @empty
    <section class="section"><div class="section-head"><h2>{{ $v['titles']['ai_visibility'] }}</h2></div><p class="empty">No source-supported AI evidence is available.</p></section>
    @endforelse
@else
<section class="section">
    <div class="section-head"><div><p class="eyebrow">@if(!$v['audit'] && $key==='overview')01 &#183; Conversions @else{{ $d['draft'] ? 'PRIVATE DRAFT PREVIEW' : 'PUBLISHED REPORT' }}@endif</p><h2>{{ $v['titles'][$key] }}</h2></div><span class="index">{{ str_pad((string)($loop->iteration),2,'0',STR_PAD_LEFT) }}</span></div>
    @if($key === 'executive_summary')
        @if(($d['summary_origin'] ?? '') === 'generated')<p class="section-note">Generated factual source summary. Missing figures and comparisons are not estimated.</p>@endif
        @if(in_array($v['summary_state'], ['approved','source_backed']))
            <p class="section-note"><span class="pill">Source-backed commentary</span> Revision {{ $d['summary_revision'] ?? 'captured at publication' }}.</p>
        @elseif($v['summary_state'] === 'missing')
            <div class="notice neutral"><strong>Executive commentary has not been supplied.</strong><p>No commentary was supplied. The report retains available source observations without invented interpretations.</p></div>
        @else
            <div class="notice"><strong>Commentary {{ $v['summary_state'] === 'outdated' ? 'requires renewed source review' : 'is a source summary' }}.</strong><p>Any saved commentary below is provisional. {{ $v['summary_state'] === 'outdated' ? 'Its source context has changed since it was saved.' : 'This narrative does not imply human source verification.' }} It must not be treated as an approved executive interpretation.</p></div>
        @endif
        @foreach($d['summary'] as $section=>$text)
            @if(trim($text ?? ''))<h3 class="narrative-title">{{ $v['titles'][$section] ?? $section }}</h3><p class="narrative">{{ $text }}</p>@endif
        @endforeach
        @if($d['draft'])
            <p class="section-note">Review status: {{ $v['provisional'] }} of {{ count($v['records']) }} business observations are provisional; {{ $v['warning_count'] }} unresolved blocking warnings remain. Uncertain observations retain their labels; source conflicts must be corrected before sharing.</p>
        @endif
    @elseif($key === 'overview')
        @if(!$v['audit'])
            @include('reports.partials.conversions')
        @else
        @php $overviewKeys=['ga4.views','ga4.total_users','ga4.new_users','ga4.organic_search_users','gsc.clicks','gsc.impressions','gsc.ctr','events.reported_total'];$overviewCards=array_values(array_filter($d['kpis'],fn($k)=>in_array($k['metric_key'],$overviewKeys))); @endphp
        @include('reports.partials.kpis', ['cards'=>$v['audit'] ? $d['kpis'] : ($overviewCards ?: $d['kpis'])])
        @if(!empty($d['summary']['website_traffic']))<p class="narrative">{{ $d['summary']['website_traffic'] }}</p>@endif
        <p class="section-note">No prior-period comparisons or trends are inferred. KPI totals are preserved separately from chart observations; channel counts are not deduplicated unique users.</p>
        @endif
    @else
        @php $rows = array_values(array_filter($v['records'], fn($r) => $r['section_key'] === $key)); @endphp
        @if(!$v['audit'])
            @php $prefix = ['website_traffic'=>'ga4.', 'organic_search'=>'gsc.', 'key_events'=>'events.'][$key] ?? null; @endphp
            @if($prefix) @include('reports.partials.kpis', ['cards'=>array_values(array_filter($d['kpis'], fn($k)=>str_starts_with($k['metric_key'], $prefix)))]) @endif
        @endif
        @if(!empty($d['summary'][$key]))<p class="narrative">{{ $d['summary'][$key] }}</p><p class="section-note">Commentary: {{ str_replace('_',' ', $v['summary_state']) }}.</p>@endif
        @if(!$rows)<p class="empty">No source-supported observations are available for this section.</p>@endif
        @if($key === 'keywords')
            @php $keywords = array_values(array_filter($rows, fn($r) => $r['kind'] === 'keyword_rankings')); @endphp
            @php $rankingCounts = []; foreach($keywords as $keyword){$label=$keyword['data']['ranking_label'] ?? 'Not reported';$rankingCounts[$label]=($rankingCounts[$label] ?? 0)+1;} @endphp
            @include('reports.partials.chart', ['chartRows'=>array_map(fn($label,$value)=>['label'=>$label,'value'=>$value],array_keys($rankingCounts),array_values($rankingCounts)), 'chartTitle'=>'Reported ranking labels', 'chartNote'=>'Page groups retain their reported meaning; they are not exact positions.'])
            @php $categoryCounts=[]; foreach($keywords as $keyword){$category=$keyword['data']['category'] ?? 'unspecified';$categoryCounts[$category]=($categoryCounts[$category] ?? 0)+1;} @endphp
            @if(!$v['audit']) @include('reports.partials.donut', ['chartRows'=>array_map(fn($label,$value)=>['label'=>$label,'value'=>$value],array_keys($categoryCounts),array_values($categoryCounts)), 'chartTitle'=>'Keyword categories', 'chartNote'=>'Counts of reported keyword observations.']) @endif
            <p class="intro">{{ count($keywords) }} keyword observations. Ranking labels are reproduced as reported: a page group such as “1st Page” is not an exact search position. Missing dates and locations are marked Not reported.</p>
        @elseif($key === 'seo_work')
            @if(!$v['audit'])
                @include('reports.partials.activities')
            @else
            <div class="facts"><div class="fact"><strong>{{ $v['activity_total'] }}</strong><span>Reported activity observations</span></div><div class="fact"><strong>{{ count($v['activity_counts']) }}</strong><span>Reported activity categories</span></div><div class="fact"><strong>{{ $v['duplicate_observations'] }}</strong><span>Observations sharing a reported URL</span></div></div>
            @include('reports.partials.chart', ['chartRows'=>array_map(fn($label,$value)=>['label'=>$label,'value'=>$value], array_keys($v['activity_counts']), array_values($v['activity_counts'])), 'chartTitle'=>'Reported SEO activity mix', 'chartNote'=>'Counts include every source observation, including suspected duplicates. Listed URLs do not establish successful publication or independently verified live backlinks.'])
            @endif
        @elseif($key === 'key_events' && $v['event_rows'])
            <p class="intro">The listed event-category observations sum to {{ $presenter->number($v['event_total']) }} across {{ count($v['event_rows']) }} observations. Event counts are not automatically verified leads or conversions. Any separately reported total is preserved in its own metric row.</p>
            @include('reports.partials.chart', ['chartRows'=>$v['event_rows'], 'chartTitle'=>'Reported event-category breakdown', 'chartNote'=>'Source-reported event counts; no conversion rate, revenue or period comparison is inferred.'])
        @endif
        @foreach(['traffic_channels'=>['total_users','channel','Traffic channel observations'], 'device_metrics'=>['clicks','device','Search clicks by device']] as $chartKind=>$chartConfig)
            @php $observations = array_values(array_filter($rows, fn($r) => $r['kind'] === $chartKind)); @endphp
            @if($observations)
                @if(!$v['audit']) @include('reports.partials.donut', ['chartRows'=>array_map(fn($r)=>['label'=>$r['data'][$chartConfig[1]],'value'=>$r['data'][$chartConfig[0]] ?? null],$observations), 'chartTitle'=>$chartKind === 'traffic_channels' ? 'Traffic source mix' : 'Device distribution', 'chartNote'=>'Source-period observations only. Channel users are not a deduplicated total.']) @endif
                @if($chartKind === 'traffic_channels' && !$v['audit']) @include('reports.partials.channel-comparison') @endif
                @if($v['audit'] || $chartKind !== 'traffic_channels')
                @include('reports.partials.chart', ['chartRows'=>array_map(fn($r)=>['label'=>$r['data'][$chartConfig[1]],'value'=>$r['data'][$chartConfig[0]] ?? null],$observations), 'chartTitle'=>$chartConfig[2], 'chartNote'=>$chartKind === 'traffic_channels' ? 'Reported channel users. These observations are not a deduplicated total and do not replace the GA4 total-users KPI.' : 'Source-reported GSC device clicks. Missing values are not estimated.'])
                @endif
            @endif
        @endforeach
        @foreach(['report_metrics'=>'Reported metrics','traffic_channels'=>'Traffic channel detail','device_metrics'=>'Device detail','query_metrics'=>'Top search queries','landing_page_metrics'=>'Landing pages','keyword_rankings'=>'Keyword observations','seo_activities'=>'Activity observations','event_metrics'=>'Event categories'] as $kind=>$tableTitle)
            @php $tableRows = array_values(array_filter($rows, fn($r) => $r['kind'] === $kind)); @endphp
            @if($tableRows && ($v['audit'] || $kind!=='seo_activities'))
                @include('reports.partials.table', compact('kind','tableTitle','tableRows'))
            @endif
        @endforeach
        <p class="legend">Evidence keys identify the source locations below. Provisional labels apply to their charts and tables.</p>
        @if(!$v['audit'])
        @php $sectionRefs=collect($rows)->flatMap(fn($r)=>$r['refs'])->unique(); @endphp
        @if($sectionRefs->isNotEmpty())<div class="section-evidence"><h3>Section evidence</h3><ul>@foreach($sectionRefs as $ref)@php $source=$v['sources'][$ref] ?? []; @endphp<li><strong>{{ $ref }}</strong> {{ $source['document'] ?? 'Source unavailable' }}: @foreach(($source['location'] ?? []) as $field=>$value){{ ucwords(str_replace('_',' ',$field)) }} {{ is_array($value) ? json_encode($value) : $value }}; @endforeach</li>@endforeach</ul></div>@endif
        @if($key==='key_events' && !empty($d['disclosures']))<div class="section-evidence"><h3>Report limitations</h3><ul>@foreach($d['disclosures'] as $note)<li>{{ $note['document'] ?? str_replace('_',' ',$note['section']) }}: {{ $note['message'] }}</li>@endforeach</ul></div>@endif
        @endif
    @endif
</section>
@endif
@endforeach
@if($v['audit'])
    @include('reports.partials.audit')
@endif
</body></html>
