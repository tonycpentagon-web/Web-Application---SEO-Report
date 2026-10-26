<?php
namespace App\Services;
use App\Models\{ReportingPeriod,User};
use Illuminate\Database\Eloquent\Builder;
class ReportWorkspace {
 public function filter(Builder $q,string $tab):Builder {
  if($tab==='trash')return $q->onlyTrashed();
  if($tab==='history')return $q->where(fn($q)=>$q->where('status','archived')->orWhere(fn($q)=>$q->where('status','published')->whereNotNull('workspace_archived_at')));
  return $q->where('status','!=','archived')->where(fn($q)=>$q->where('status','!=','published')->orWhereNull('workspace_archived_at'));
 }
 public function file(ReportingPeriod $p,User $u):void {
  abort_unless($p->status==='published',409,'Only Published reports can be filed without changing client access.');
  if(!$p->workspace_archived_at){$p->forceFill(['workspace_archived_at'=>now()])->save();app(ReportDelivery::class)->event($p,null,$u,'workspace.archived');}
 }
}
