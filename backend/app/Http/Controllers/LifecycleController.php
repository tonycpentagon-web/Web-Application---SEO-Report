<?php
namespace App\Http\Controllers;
use App\Services\WorkspaceLifecycle;
use Illuminate\Http\Request;
class LifecycleController extends Controller {
 public function __construct(public WorkspaceLifecycle $life){}
 public function preview(Request $r,string $type,string $id){return response()->json(['data'=>$this->life->preview($r->user(),$type,$id)]);}
 public function mutate(Request $r,string $type,string $id){$v=$r->validate(['action'=>'required|in:archive,restore,restore_active,trash,purge,reset','confirmed'=>'required|accepted','preview_token'=>'required|string|size:64','confirmation'=>'nullable|string|max:80']);$this->life->mutate($r->user(),$type,$id,$v);return response()->json(['message'=>'Change saved. Restored items do not reactivate revoked sharing permissions.']);}
 public function index(Request $r){
  abort_unless($this->life->access->administrator($r->user()),403);$r->validate(['type'=>'nullable|in:client,project,report,document','page'=>'nullable|integer|min:1']);$type=$r->input('type','report');$class=WorkspaceLifecycle::MODELS[$type];
  // Filter permitted IDs in SQL, including parent Trash; never disclose unrelated names/counts.
  $q=$class::withoutGlobalScopes()->whereNotNull($class===\App\Models\Client::class?'clients.deleted_at':(new $class)->getTable().'.deleted_at');
  if(!$this->life->access->super($r->user())){
   $clients=\App\Models\Client::withTrashed()->where(fn($c)=>$c->where('user_id',$r->user()->id)->orWhereHas('admins',fn($a)=>$a->where('users.id',$r->user()->id)))->select('id');
   if($type==='client')$q->whereIn('id',$clients);
   else {$projects=\App\Models\Project::withoutGlobalScopes()->where(fn($p)=>$p->whereIn('client_id',$clients)->orWhere('user_id',$r->user()->id))->select('id');$q->whereIn($type==='project'?'id':'project_id',$projects);}
  }
  return $q->orderByDesc('deleted_at')->paginate(15)->through(fn($m)=>['id'=>$m->id,'type'=>$type,'name'=>$m->name??$m->title??$m->original_filename,'deleted_at'=>$m->deleted_at,'purge_after'=>$m->deleted_at->copy()->addDays(config('retention.trash_days'))]);
 }
}
