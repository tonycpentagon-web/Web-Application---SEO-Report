@php
$maximum = max(array_merge([1], array_map(fn($r) => max($r['data']['total_users'] ?? 0, $r['data']['new_users'] ?? 0), $observations)));
@endphp
<div class="chart"><h3>Total users vs new users by channel</h3><p class="chart-note">Cyan: Total users / Navy: New users. Missing values are not estimated.</p>
@foreach($observations as $r)
@foreach(['total_users'=>'Total users','new_users'=>'New users'] as $field=>$series)
@if(is_numeric($r['data'][$field] ?? null))
<div class="bar-row"><span class="bar-label">{{ $r['data']['channel'] }} / {{ $series }}</span><svg class="bar-track" viewBox="0 0 400 15" preserveAspectRatio="none" role="img" aria-label="{{ $r['data']['channel'] }} {{ $series }}: {{ $r['data'][$field] }}"><rect width="400" height="15" fill="#e2edf1" rx="3"/><rect width="{{ max(0,400*$r['data'][$field]/$maximum) }}" height="15" fill="{{ $field === 'total_users' ? '#00ade2' : '#203444' }}" rx="3"/></svg><span class="bar-value">{{ $presenter->number($r['data'][$field]) }}</span></div>
@endif
@endforeach
@endforeach
</div>
