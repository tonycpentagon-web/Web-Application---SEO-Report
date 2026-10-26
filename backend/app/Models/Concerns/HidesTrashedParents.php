<?php
namespace App\Models\Concerns;
use Illuminate\Database\Eloquent\Builder;
trait HidesTrashedParents {
 protected static function bootHidesTrashedParents():void {
  static::addGlobalScope('active_parents',function(Builder $q){
   $table=$q->getModel()->getTable();
   if($table==='projects') $q->whereNotExists(fn($x)=>$x->selectRaw('1')->from('clients as lifecycle_client')->whereColumn('lifecycle_client.id','projects.client_id')->whereNotNull('lifecycle_client.deleted_at'));
   else $q->whereExists(fn($x)=>$x->selectRaw('1')->from('projects as lifecycle_project')->whereColumn('lifecycle_project.id',$table.'.project_id')->whereNull('lifecycle_project.deleted_at')->whereNotExists(fn($c)=>$c->selectRaw('1')->from('clients as lifecycle_client')->whereColumn('lifecycle_client.id','lifecycle_project.client_id')->whereNotNull('lifecycle_client.deleted_at')));
  });
 }
}
