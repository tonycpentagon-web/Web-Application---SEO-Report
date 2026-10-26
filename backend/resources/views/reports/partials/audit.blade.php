<section class="appendix">
    <div class="section-head"><div><p class="eyebrow">PRIVATE / INTERNAL AUDIT EXPORT</p><h2>Record Registry and Review History</h2></div></div>
    <p class="intro">This appendix is internal. It preserves record IDs, effective saved data, all retained source references, correction history, human verification and validation history captured when this export was requested. Historical entries are not automatically current approvals or warning resolutions.</p>
    @if(empty($d['_audit']))
        <div class="notice">This legacy export payload did not capture review-history metadata. No history has been inferred. Request a new Internal Audit Export for a complete current capture.</div>
    @else
        @php $audit = $d['_audit']; @endphp
        <p class="section-note">Captured {{ $audit['captured_at'] }}. Registry entries: {{ count($audit['registry']) }}; source references: {{ array_sum(array_map(fn($r)=>count($r['sources']), $audit['registry'])) }}. Active and inactive, classified and unclassified, approved, pending and rejected records are retained.</p>
        <h3>Source document manifest</h3><pre class="audit-json">{{ $presenter->json($audit['documents']) }}</pre>
        @foreach($audit['registry'] as $entry)
        <div class="audit-record">
            <h3>{{ $entry['kind'] }} / {{ $entry['id'] }}</h3>
            <p class="ref">{{ $entry['active'] ? 'Active' : 'Inactive / historical' }} · {{ $entry['review_status'] }} · Revision {{ $entry['revision'] }} · Normalization {{ $entry['normalization_run_id'] }}</p>
            <p class="ref">Source key {{ $entry['source_key'] }}<br>Source hash {{ $entry['source_hash'] }}</p>
            <p class="audit-label">Effective saved data</p><pre class="audit-json">{{ $presenter->json($entry['data'], false) }}</pre>
            @foreach($entry['sources'] as $source)
                <p class="audit-label">Source reference {{ $source['id'] ?? '' }}</p><pre class="audit-json">{{ $presenter->json($source, false) }}</pre>
            @endforeach
            @foreach($entry['corrections'] as $correction)
                <p class="audit-label">Review / correction history</p><pre class="audit-json">{{ $presenter->json($correction, false) }}</pre>
            @endforeach
        </div>
        @endforeach
        @foreach(['verifications'=>'Human source-verification history','warnings'=>'Complete validation warning history','summary_revisions'=>'Executive commentary revision history','summary_approvals'=>'Executive commentary approval history','report_history'=>'Report activity and review audit history','normalization_runs'=>'Normalization attempt history'] as $field=>$heading)
            <div class="section-head" style="margin-top:24px"><h2>{{ $heading }}</h2></div>
            @forelse($audit[$field] as $entry)<pre class="audit-json">{{ $presenter->json($entry, false) }}</pre>@empty<p class="empty">No entries were recorded at export time.</p>@endforelse
        @endforeach
    @endif
</section>
