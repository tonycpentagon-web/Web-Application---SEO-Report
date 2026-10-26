<div class="kpis">
@foreach($cards as $k)
<div class="kpi"><div class="kpi-label">{{ $k['label'] }}</div><div class="kpi-value">{{ !empty($k['conflict']) ? 'Conflicting values' : ($k['value'] === null ? 'Not reported' : (($k['unit'] ?? '') === 'seconds' ? floor($k['value']/60).':'.str_pad((string)floor(fmod((float)$k['value'],60)),2,'0',STR_PAD_LEFT) : $presenter->number($k['value']).(($k['unit'] ?? '') === 'percent' ? '%' : ''))) }}</div><div class="kpi-unit">{{ ($k['unit'] ?? '') === 'seconds' ? 'Minutes : seconds' : ($k['unit'] ?? '') }}</div>
@if(!empty($k['provisional']))<span class="pill pending">Provisional / unverified</span>@endif
</div>
@endforeach
</div>
