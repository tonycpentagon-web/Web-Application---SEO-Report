<?php
namespace App\Services;
use App\Models\ReportingPeriod;
use Illuminate\Support\Facades\DB;
class DraftInvalidation {
 public function invalidate(ReportingPeriod $p):void {
  abort_unless($p->status==='draft',409,'Only Draft report data can be changed.');
  DB::table('normalized_records')->where('reporting_period_id',$p->id)->where('active',true)->update(['active'=>false,'updated_at'=>now()]);
  $p->forceFill(['requires_regeneration'=>true,'version'=>$p->version+1])->save();
 }
 public function assertIdle(array $reports):void {
  foreach(['report_generations','normalization_runs','report_pdf_exports'] as $table) abort_if(DB::table($table)->whereIn('reporting_period_id',$reports)->whereIn('status',['queued','processing','rendering'])->exists(),409,'Wait for report processing and PDF export to finish before making this change.');
  abort_if(DB::table('document_extraction_runs')->whereIn('document_id',DB::table('report_documents')->whereIn('reporting_period_id',$reports)->select('id'))->whereIn('status',['queued','processing','rendering'])->exists(),409,'Wait for document processing to finish before making this change.');
 }
}
