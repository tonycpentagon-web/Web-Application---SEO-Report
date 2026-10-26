@php
$segments=array_values(array_filter($chartRows,fn($row)=>isset($row['value']) && is_numeric($row['value']) && $row['value'] >= 0));
$total=array_sum(array_column($segments,'value'));
$colors=['#00ade2','#203444','#5fc7e6','#467d9d','#738d9e','#a1dceb','#a7b8c3'];$offset=0;$circumference=2*pi()*75;
@endphp
@if($segments)
<div class="chart"><h3>{{ $chartTitle }}</h3>
@if($total>0)
<div style="display:flex;align-items:center;gap:20px"><svg viewBox="0 0 220 220" style="width:38%;height:150px" role="img" aria-label="{{ $chartTitle }}">
@foreach($segments as $segment)
@php $length=$circumference*$segment['value']/$total; @endphp
<circle cx="110" cy="110" r="75" fill="none" stroke="{{ $colors[$loop->index % count($colors)] }}" stroke-width="30" stroke-dasharray="{{ $length }} {{ $circumference-$length }}" stroke-dashoffset="{{ -$offset }}" transform="rotate(-90 110 110)"/>
@php $offset += $length; @endphp
@endforeach
</svg><div style="flex:1">
@foreach($segments as $segment)<p style="font-size:9px;margin-bottom:5px"><span style="color:{{ $colors[$loop->index % count($colors)] }}">&#9632;</span> {{ ucwords(str_replace('_',' ',$segment['label'])) }} <strong style="float:right">{{ $presenter->number($segment['value']) }}</strong></p>@endforeach
</div></div>
@else<p>No nonzero source observations are available for a distribution.</p>@endif
<p class="chart-note">{{ $chartNote }}</p></div>
@endif
