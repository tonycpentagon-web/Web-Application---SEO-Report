@php
$eventRecords=collect($v['records'])->where('kind','event_metrics')->sortByDesc(fn($r)=>$r['data']['count']??-1)->values();
$eventTotal=collect($d['kpis'])->firstWhere('metric_key','events.reported_total');
$eventCards=[['label'=>'Grand total','value'=>$eventTotal['value']??null,'unit'=>'count','conflict'=>$eventTotal['conflict']??false,'provisional'=>$eventTotal['provisional']??false]];
foreach($eventRecords as $event)$eventCards[]=['label'=>$event['data']['event_name']??'Unnamed event','value'=>$event['data']['count']??null,'unit'=>'count','provisional'=>$event['provisional']??false];
@endphp
@include('reports.partials.kpis',['cards'=>$eventCards])
@if($eventRecords->isNotEmpty())
@include('reports.partials.chart',['chartRows'=>$eventRecords->map(fn($r)=>['label'=>$r['data']['event_name']??'Unnamed event','value'=>$r['data']['count']??null])->all(),'chartTitle'=>'Event count by event name','chartNote'=>'Source-reported event counts.'])
<table class="report-table"><caption>Conversion events</caption><thead><tr><th>Event name</th><th>Event count</th></tr></thead><tbody>
@foreach($eventRecords as $event)<tr><td>{{ $event['data']['event_name']??'Unnamed event' }}@if($event['provisional']??false)<span class="row-state">Provisional</span>@endif</td><td>{{ $presenter->number($event['data']['count']??null) }}</td></tr>@endforeach
</tbody><tfoot><tr><th>Grand total</th><td>{{ ($eventTotal['conflict']??false)?'Conflicting source values':$presenter->number($eventTotal['value']??null) }}</td></tr></tfoot></table>
@else<p class="empty">No source-supported conversion event observations were supplied.</p>@endif
<p class="section-note">Source-reported events. Counts are not independently verified leads or sales; a grand total is shown only when supplied by the source.</p>
@if(!empty($d['summary']['key_events']))<p class="narrative">{{ $d['summary']['key_events'] }}</p>@endif
