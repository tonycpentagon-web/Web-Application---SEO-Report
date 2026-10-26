@php
$numericRows = array_values(array_filter($chartRows, fn($row) => isset($row['value']) && is_numeric($row['value'])));
$maximum = max(array_merge([1], array_column($numericRows, 'value')));
@endphp
@if($numericRows)
<div class="chart">
    <h3>{{ $chartTitle }}</h3>
    @foreach($numericRows as $bar)
    <div class="bar-row">
        <span class="bar-label">{{ ucwords(str_replace('_', ' ', $bar['label'])) }}</span>
        <svg class="bar-track" viewBox="0 0 400 15" preserveAspectRatio="none" role="img" aria-label="{{ $bar['label'] }}: {{ $bar['value'] }}">
            <rect width="400" height="15" fill="#e2edf1" rx="3"/>
            <rect width="{{ 400 * $bar['value'] / $maximum }}" height="15" fill="{{ ['#00ade2','#203444','#5fc7e6','#467d9d','#738d9e','#a1dceb','#a7b8c3'][$loop->index % 7] }}" rx="3"/>
        </svg>
        <span class="bar-value">{{ $presenter->number($bar['value']) }}</span>
    </div>
    @endforeach
    <p class="chart-note">{{ $chartNote }}</p>
</div>
@endif
