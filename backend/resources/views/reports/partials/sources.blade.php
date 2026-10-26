<section class="appendix">
    <div class="section-head"><div><p class="eyebrow">REFERENCE APPENDIX</p><h2>{{ $v['titles']['sources'] }}</h2></div></div>
    <div class="methodology"><p>{{ $d['methodology'] }}</p><ul>
        <li>Report coverage: {{ $d['report']['start_date'] }} – {{ $d['report']['end_date'] }}. The source catalogue preserves separately reported source periods.</li>
        <li>R keys identify the unchanged business observations in this export; S keys identify their document and evidence location. Records are not silently merged or discarded.</li>
        <li>Keyword page groups remain reported labels, not exact positions. Missing dates, locations and values are not filled with estimates.</li>
        <li>Activity charts count published source observations. Repeated identical submissions are counted once when the publication combines their citations. A listed URL is not proof of a verified live backlink.</li>
        <li>AI screenshots are evidence of the captured page. Unreviewed observations do not establish verified brand visibility, traffic or ranking.</li>
    </ul></div>
    @if($d['draft'])
    <div class="notice"><strong>Draft validation overview</strong><p>{{ $v['provisional'] }} provisional business observations; {{ $v['warning_count'] }} unresolved blocking warning IDs. Publication and client sharing remain subject to the existing live readiness checks.</p>
        @foreach($v['warning_groups'] as $code=>$count)<span class="pill pending">{{ str_replace('_',' ',$code) }}: {{ $count }}</span> @endforeach
        <p class="section-note" style="margin-top:8px">Examples include source-period differences, partial extraction and OCR uncertainty when present in the source registry. Use Internal Audit Export for record IDs, complete review history and every validation warning. This preview changes no review decisions.</p>
    </div>
    @endif
    <table class="report-table source-table"><caption>Evidence catalogue</caption><colgroup><col style="width:10%"><col style="width:57%"><col style="width:33%"></colgroup>
    <thead><tr><th>Key</th><th>Source document</th><th>Location / reported period</th></tr></thead><tbody>
        @foreach($v['sources'] as $ref=>$source)
        <tr><td><strong>{{ $ref }}</strong></td><td>{{ $source['document'] ?? 'Source unavailable' }}</td><td>
            @foreach($source['location'] as $field=>$value){{ ucwords(str_replace('_',' ',$field)) }} {{ is_array($value) ? json_encode($value) : $value }}; @endforeach
            @foreach(($source['source_period'] ?? []) as $period)<div class="source-period">{{ $period['start'] ?? 'Not reported' }} – {{ $period['end'] ?? 'Not reported' }}</div>@endforeach
        </td></tr>
        @endforeach
    </tbody></table>
    @if(!$v['sources'])<p class="empty">No source references were available at export time.</p>@endif
</section>

@if(!empty($d['disclosures']))<section class="section"><h3>Extraction details and limitations</h3><ul>@foreach($d['disclosures'] as $note)<li>{{ $note['document'] ?? str_replace('_',' ', $note['section']) }}: {{ $note['message'] }}</li>@endforeach</ul></section>@endif
