<?php

namespace App\Services;

use App\Models\NormalizationRun;
use App\Models\NormalizedRecord;
use App\Models\ReportingPeriod;
use Illuminate\Support\Facades\DB;

class ReportSourceAudit
{
    public function build(ReportingPeriod $period): array
    {
        $run = NormalizationRun::where('reporting_period_id', $period->id)->latest()->orderByDesc('id')->first();
        $classifications = collect($run?->summary['classifications'] ?? [])->keyBy('document_id');
        $records = NormalizedRecord::where('reporting_period_id', $period->id)->where('active', true)->get();
        $references = DB::table('metric_source_references')->where('normalization_run_id', $run?->id ?? 'none')->get();
        $byId = $records->keyBy('id');
        $warnings = DB::table('validation_warnings')->where('normalization_run_id', $run?->id ?? 'none')->get();
        $documents = [];
        foreach ($period->documents()->with('currentRun')->get() as $doc) {
            $attempt = $doc->currentRun;
            $content = $attempt?->content()->get() ?? collect();
            $refs = $references->where('document_id', $doc->id)->where('extraction_run_id', $doc->current_run_id);
            $mapped = $refs->pluck('normalized_record_id')->unique()->map(fn ($id) => $byId->get($id))->filter();
            $businessRefs = $refs->filter(fn ($ref) => $byId->has($ref->normalized_record_id) && $byId->get($ref->normalized_record_id)->kind !== 'unclassified');
            $consumed = $businessRefs->pluck('content_id')->filter()->unique();
            $units = collect($attempt?->statistics['units'] ?? []);
            $documents[] = ['id' => $doc->id, 'name' => $doc->original_filename, 'extraction_status' => $doc->extraction_status,
                'attempts_retained' => $doc->runs()->count(), 'current_run_id' => $doc->current_run_id,
                'source_units' => $attempt?->statistics['metadata'] ?? [], 'units_inspected' => $units->count(), 'units' => $units,
                'extracted_content_units' => $content->count(), 'content_types' => $content->countBy('content_type'),
                'classified_content_units' => $consumed->count(), 'content_without_business_reference' => $content->whereNotIn('id', $consumed)->count(),
                'asset_units' => $attempt?->assets()->count() ?? 0, 'normalized_records' => $mapped->count(), 'business_records' => $mapped->where('kind', '!=', 'unclassified')->count(),
                'record_kinds' => $mapped->countBy('kind'), 'unclassified_records' => $mapped->where('kind', 'unclassified')->count(),
                'classification' => $classifications->get($doc->id)['labels'] ?? [], 'source_periods' => $classifications->get($doc->id)['source_dates'] ?? [],
                'extraction_warnings' => $attempt?->warnings ?? [], 'review_warnings' => $warnings->where('document_id', $doc->id)->values(),
                'incomplete_units' => $units->filter(fn ($unit) => ! in_array($unit['status'] ?? '', ['processed', 'extracted', 'complete', 'completed', 'ok']) || ! empty($unit['errors']))->values(), 'error' => $attempt?->error_message];
        }
        $business = $records->where('kind', '!=', 'unclassified');

        return ['report_id' => $period->id, 'project_id' => $period->project_id, 'normalization_run_id' => $run?->id,
            'normalization_status' => $run?->status, 'documents' => $documents,
            'totals' => ['uploaded_documents' => count($documents), 'active_normalized_records' => $records->count(), 'business_observations' => $business->count(),
                'unclassified_records' => $records->where('kind', 'unclassified')->count(), 'record_kinds' => $records->countBy('kind'), 'validation_warnings' => $warnings->count(),
                'retained_source_references' => $references->count(), 'key_events_source_missing' => ! $business->contains('kind', 'event_metrics')],
            'interpretation' => 'Counts describe retained source representations, not extraction accuracy. A page, image, text block, URL and table may represent the same source information. Not every extracted unit is a business observation. Unclassified content is retained for inspection.'];
    }
}
