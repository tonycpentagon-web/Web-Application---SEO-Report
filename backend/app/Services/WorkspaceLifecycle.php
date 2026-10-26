<?php
namespace App\Services;
use App\Models\{Client,Project,ReportingPeriod,ReportDocument,User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class WorkspaceLifecycle {
 public const MODELS=['client'=>Client::class,'project'=>Project::class,'report'=>ReportingPeriod::class,'document'=>ReportDocument::class];
 public function __construct(public WorkspaceAccess $access,public DraftInvalidation $drafts){}
 public function find(User $u,string $type,string $id){
  abort_unless(isset(self::MODELS[$type]),404);$m=self::MODELS[$type]::withoutGlobalScopes()->findOrFail($id);
  abort_if($this->access->role($u)==='client_viewer',403);
  if($type==='client') abort_unless($this->access->manageClient($u,$m),403);
  else {$p=$type==='project'?$m:Project::withoutGlobalScopes()->findOrFail($m->project_id);$p->setRelation('client',$p->client_id?Client::withTrashed()->find($p->client_id):null);
   if($type==='document') {$period=ReportingPeriod::withoutGlobalScopes()->findOrFail($m->reporting_period_id);abort_unless($this->access->workReports($u,$p)&&($m->uploaded_by===$u->id||$this->access->manageProject($u,$p)),403);}
   else abort_unless($type==='report'?$this->access->workReports($u,$p):$this->access->manageProject($u,$p),403);
  }
  return $m;
 }
 public function graph(string $type,$m):array {
  $projects=$type==='client'?DB::table('projects')->where('client_id',$m->id)->pluck('id')->all():[$type==='project'?$m->id:$m->project_id];
  $reports=in_array($type,['report','document'])?[$type==='report'?$m->id:$m->reporting_period_id]:DB::table('reporting_periods')->whereIn('project_id',$projects)->pluck('id')->all();
  $documents=$type==='document'?[$m->id]:DB::table('report_documents')->whereIn('reporting_period_id',$reports)->pluck('id')->all();
  $publications=$type==='document'?[]:DB::table('report_publications')->whereIn('reporting_period_id',$reports)->pluck('id')->all();
  return [...compact('projects','reports','documents','publications'),'revoke_grants'=>$type!=='document'];
 }
 public function preview(User $u,string $type,string $id):array {
  $m=$this->find($u,$type,$id);$g=$this->graph($type,$m);
  $counts=['projects'=>$type==='client'?count($g['projects']):($type==='project'?1:0),'monthly_reports'=>$type==='document'?0:count($g['reports']),'uploaded_documents'=>count($g['documents']),'published_reports'=>count($g['publications']),'active_share_links'=>DB::table('report_share_links')->whereIn('publication_id',$g['publications'])->whereNull('revoked_at')->where('expires_at','>',now())->count(),'generated_pdfs'=>$type==='document'?0:DB::table('report_pdf_exports')->whereIn('reporting_period_id',$g['reports'])->count(),'extraction_attempts'=>DB::table('document_extraction_runs')->whereIn('document_id',$g['documents'])->count(),'derived_records'=>$type==='document'?DB::table('metric_source_references')->whereIn('document_id',$g['documents'])->distinct()->count('normalized_record_id'):DB::table('normalized_records')->whereIn('reporting_period_id',$g['reports'])->count()];
  $counts['publication_versions']=count($g['publications']);
  $counts['published_reports']=DB::table('report_publications')->whereIn('id',$g['publications'])->distinct()->count('reporting_period_id');
  $counts['client_access_grants']=$type==='document'?0:DB::table('report_shares')->whereIn('reporting_period_id',$g['reports'])->count();
  $counts['team_assignments']=DB::table('project_memberships')->whereIn('project_id',$g['projects'])->count();
  $counts['normalization_attempts']=DB::table('normalization_runs')->whereIn('reporting_period_id',$g['reports'])->count();
  $counts['commentary_revisions']=DB::table('report_summary_revisions')->whereIn('reporting_period_id',$g['reports'])->count();
  $counts['audit_events']=DB::table('project_activity_logs')->whereIn('reporting_period_id',$g['reports'])->count()+DB::table('report_delivery_events')->whereIn('reporting_period_id',$g['reports'])->count();
  $exports=$type==='document'?collect():DB::table('report_pdf_exports')->whereIn('reporting_period_id',$g['reports'])->orderBy('id')->get(['id','type','status','created_at']);
  $links=DB::table('report_share_links')->whereIn('publication_id',$g['publications'])->orderBy('id')->get(['id','label','expires_at','revoked_at','access_mode']);
  $state=[$exports,$links,$m->getRawOriginal(),$counts,DB::table('reporting_periods')->whereIn('id',$g['reports'])->orderBy('id')->get(['id','version','status','deleted_at','updated_at']),DB::table('report_documents')->whereIn('id',$g['documents'])->orderBy('id')->get(['id','deleted_at','current_run_id','updated_at'])];
  $busy=false;try{$this->drafts->assertIdle($g['reports']);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$busy=true;}
  return ['id'=>$id,'type'=>$type,'name'=>$m->name??$m->title??$m->original_filename,'status'=>$m->status??$m->extraction_status,'deleted_at'=>$m->deleted_at,'counts'=>$counts,'exports'=>$exports->take(100)->values(),'links'=>$links->take(100)->values(),'documents'=>DB::table('report_documents')->whereIn('id',$g['documents'])->limit(100)->get(['id','original_filename','deleted_at']),'retention_days'=>config('retention.trash_days'),'purge_after'=>$m->deleted_at?->copy()->addDays(config('retention.trash_days')),'can_purge'=>$this->access->administrator($u)&&(in_array($type,['report','document'])||$this->access->super($u)),'busy'=>$busy,'preview_token'=>hash_hmac('sha256',json_encode($state),config('app.key'))];
 }
 public function audit(User $u,string $type,string $id,string $action,array $metadata=[]):void {
  DB::table('retained_lifecycle_events')->insert(['id'=>(string)Str::ulid(),'entity_type'=>$type,'entity_id'=>$id,'actor_id'=>$u->id,'action'=>$action,'metadata'=>json_encode($metadata),'created_at'=>now()]);
 }
 public function mutate(User $u,string $type,string $id,array $v):void {
  DB::transaction(function()use($u,$type,$id,$v){
   $m=$this->find($u,$type,$id);$g=$this->graph($type,$m);
   // Same parent/report locks used by creation, generation, publication and source writes.
   if($type==='client') Client::withTrashed()->whereKey($id)->lockForUpdate()->firstOrFail();
   DB::table('projects')->whereIn('id',$g['projects'])->orderBy('id')->lockForUpdate()->get();
   DB::table('reporting_periods')->whereIn('id',$g['reports'])->orderBy('id')->lockForUpdate()->get();
   $m=$this->find($u,$type,$id);$g=$this->graph($type,$m);$preview=$this->preview($u,$type,$id);
   abort_unless(hash_equals($preview['preview_token'],$v['preview_token']),409,'This item or its dependencies changed. Reopen Actions and review the current counts.');
   $this->drafts->assertIdle($g['reports']);$action=$v['action'];
   if($type==='report'&&$action!=='reset'){
    $project=Project::withoutGlobalScopes()->findOrFail($m->project_id);$project->setRelation('client',$project->client_id?Client::withTrashed()->find($project->client_id):null);
    abort_unless($this->access->manageProject($u,$project)||($m->created_by===$u->id&&$this->access->workReports($u,$project)),403);
   }
   if($action==='purge'){
    abort_unless($preview['can_purge'],403);abort_unless($m->trashed(),409,'Move this item to Trash first.');
    abort_unless($m->deleted_at->lte(now()->subDays(config('retention.trash_days'))),409,'The configured Trash retention period has not elapsed.');
    abort_unless(($v['confirmation']??'')==='DELETE '.$id,422,'Type DELETE followed by the complete item ID.');
    $this->revoke($g);$this->audit($u,$type,$id,'permanently_deleted',['counts'=>$preview['counts']]);$this->purge($u,$type,$m,$g);return;
   }
   if($action==='restore_active'){
    abort_unless($type==='report'&&!$m->trashed()&&$m->status==='published',409,'Only a filed Published report can return to the active list.');
    abort_unless($m->workspace_archived_at,409,'This report is already in the active list.');
    $m->forceFill(['workspace_archived_at'=>null])->save();
   }elseif($action==='reset'){
    abort_unless($type==='report'&&$m->status==='draft'&&!$m->trashed(),409,'Only an active Draft can be reset.');
    abort_unless($this->access->workReports($u,$m->project),403);$this->drafts->invalidate($m);
   }elseif($action==='restore'){
    $this->assertParents($type,$m);
    if($m->trashed()){
     if($type==='document'){
      $p=ReportingPeriod::findOrFail($m->reporting_period_id);abort_unless($p->status==='draft',409,'Return the report to Draft before restoring a source.');
      abort_if(ReportDocument::where('reporting_period_id',$p->id)->where('active_checksum',$m->checksum)->exists(),409,'An identical source is already attached.');$m->active_checksum=$m->checksum;$m->save();$m->restore();$this->drafts->invalidate($p);
     }else $m->restore();
    }else{
     abort_unless(($m->status??null)==='archived',409,'This item is not archived or in Trash.');
     $m->forceFill(['status'=>$type==='report'?'draft':'active','lifecycle_previous_status'=>null])->save();
     if($type==='report')$m->forceFill(['version'=>$m->version+1])->save();
    }
   }elseif($action==='archive'){
    abort_if($type==='document'||$m->trashed()||$m->status==='archived',409,'This item cannot be archived.');
    if($type==='report'&&$m->status==='published')app(ReportWorkspace::class)->file($m,$u);
    else {
     $m->forceFill(['lifecycle_previous_status'=>$m->status,'status'=>'archived'])->save();
     if($type==='report')$m->forceFill(['version'=>$m->version+1])->save();
    }
   }elseif($action==='trash'){
    abort_if($m->trashed(),409,'This item is already in Trash.');
    if($type==='document'){$p=ReportingPeriod::findOrFail($m->reporting_period_id);abort_unless(app(DocumentAccess::class)->canManage($u,$m),403);$this->drafts->invalidate($p);$m->forceFill(['active_checksum'=>null,'deleted_by'=>$u->id])->save();}
    $this->revoke($g);$m->delete();
   }
   $this->audit($u,$type,$id,$action,['counts'=>$preview['counts']]);
  },5);
 }
 private function assertParents(string $type,$m):void {
  if($type==='client')return;
  $p=$type==='project'?$m:Project::withoutGlobalScopes()->findOrFail($m->project_id);
  abort_if($type!=='project'&&$p->trashed(),409,'Restore the parent project first.');
  abort_if($p->client_id&&Client::withTrashed()->findOrFail($p->client_id)->trashed(),409,'Restore the parent client first.');
  if($type==='document')abort_if(ReportingPeriod::withoutGlobalScopes()->findOrFail($m->reporting_period_id)->trashed(),409,'Restore the monthly report first.');
 }
 private function revoke(array $g):void {
  DB::table('report_share_links')->whereIn('publication_id',$g['publications'])->whereNull('revoked_at')->update(['revoked_at'=>now(),'updated_at'=>now()]);
  if($g['revoke_grants']) DB::table('report_shares')->whereIn('reporting_period_id',$g['reports'])->update(['expires_at'=>now(),'updated_at'=>now()]);
 }
 private function purge(User $u,string $type,$m,array $g):void {
  $docs=$g['documents'];$reports=$g['reports'];
  $paths=DB::table('report_documents')->whereIn('id',$docs)->pluck('storage_path')->merge(DB::table('document_assets')->whereIn('document_id',$docs)->pluck('storage_path'))->merge(DB::table('document_extraction_runs')->whereIn('document_id',$docs)->whereNotNull('raw_path')->pluck('raw_path'));
  if($type!=='document'){
   $paths=$paths->merge(DB::table('report_pdf_exports')->whereIn('reporting_period_id',$reports)->whereNotNull('storage_path')->pluck('storage_path'));
   foreach(DB::table('report_pdf_exports')->whereIn('reporting_period_id',$reports)->pluck('id') as $export)$paths->push('exports/'.$export.'/input.html')->push('exports/'.$export.'/report.pdf');
  }
  if($type==='client'&&$m->logo_path)$paths->push($m->logo_path);
  foreach($paths->unique() as $path)DB::table('private_file_removals')->insert(['id'=>(string)Str::ulid(),'storage_path'=>$path,'created_at'=>now()]);
  // Retain historical decisions and delivery/access events before removing their FK-bound rows.
  $records=DB::table('normalized_records')->whereIn('reporting_period_id',$reports)->pluck('id');
  if($type==='document'){
   abort_if(DB::table('metric_source_references')->whereIn('document_id',$docs)->exists()||DB::table('validation_warnings')->whereIn('document_id',$docs)->exists(),409,'This source is referenced by retained report evidence. Permanently delete its owning report after retention, or keep the source in Trash.');
  }else{
   foreach(['record_corrections','record_verifications'] as $table){$q=DB::table($table)->whereIn('normalized_record_id',$records);$this->retainAndDelete($u,$type,$m->id,$table,$q);}
   foreach(['project_activity_logs','report_delivery_events','report_summary_revisions','bulk_review_requests'] as $table){$q=DB::table($table)->whereIn('reporting_period_id',$reports);if($table==='report_summary_revisions'){
    $approvals=DB::table('report_summary_approvals')->whereIn('summary_revision_id',(clone $q)->select('id'));$this->retainAndDelete($u,$type,$m->id,'report_summary_approvals',$approvals);
   }$this->retainAndDelete($u,$type,$m->id,$table,$q);}
   foreach(['report_share_links','report_shares','report_pdf_exports','report_publications','report_generations','validation_warnings','metric_source_references','normalized_records','report_sections','normalization_runs'] as $table){
    $q=DB::table($table);
    if($table==='report_share_links')$q->whereIn('publication_id',$g['publications']);
    elseif($table==='validation_warnings')$q->whereIn('normalization_run_id',DB::table('normalization_runs')->whereIn('reporting_period_id',$reports)->select('id'));
    elseif($table==='metric_source_references')$q->whereIn('normalized_record_id',$records);
    else $q->whereIn('reporting_period_id',$reports);
    $q->delete();
   }
  }
  DB::table('report_documents')->whereIn('id',$docs)->update(['current_run_id'=>null]);
  foreach(['document_assets','document_extracted_content','document_extraction_runs'] as $table)DB::table($table)->whereIn('document_id',$docs)->delete();
  DB::table('report_documents')->whereIn('id',$docs)->delete();
  if($type==='document')return;
  DB::table('reporting_periods')->whereIn('id',$reports)->delete();
  if(in_array($type,['client','project'])){
   $this->retainAndDelete($u,$type,$m->id,'project_activity_logs',DB::table('project_activity_logs')->whereIn('project_id',$g['projects']));
   DB::table('project_memberships')->whereIn('project_id',$g['projects'])->delete();DB::table('projects')->whereIn('id',$g['projects'])->delete();
  }
  if($type==='client'){DB::table('client_memberships')->where('client_id',$m->id)->delete();DB::table('clients')->where('id',$m->id)->delete();}
 }
 private function retainAndDelete(User $u,string $type,string $id,string $table,$q):void {
  foreach((clone $q)->get() as $row)$this->audit($u,$type,$id,'retained.'.$table,(array)$row);$q->delete();
 }
}
