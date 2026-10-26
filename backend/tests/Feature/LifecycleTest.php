<?php
namespace Tests\Feature;
use App\Models\{Client,Project,ReportingPeriod,ReportDocument,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Queue,Storage};
use Tests\TestCase;
class LifecycleTest extends TestCase {
 use RefreshDatabase;
 private User $admin;private Client $client;private Project $project;
 protected function setUp():void{parent::setUp();Storage::fake('local');Queue::fake();$this->admin=User::factory()->create(['role'=>'admin','is_super_admin'=>false]);$this->client=new Client(['name'=>'Synthetic lifecycle client','status'=>'active']);$this->client->forceFill(['user_id'=>$this->admin->id,'identity_key'=>hash('sha256','lifecycle')])->save();$this->project=Project::factory()->create(['client_id'=>$this->client->id,'user_id'=>$this->admin->id]);$this->actingAs($this->admin);}
 private function period(string $month='2026-09'):ReportingPeriod{return $this->project->reports()->create(['title'=>'Synthetic '.$month,'kind'=>'monthly','reporting_month'=>$month,'start_date'=>$month.'-01','end_date'=>date('Y-m-t',strtotime($month.'-01')),'status'=>'draft','created_by'=>$this->admin->id,'updated_by'=>$this->admin->id])->refresh();}
 private function change(string $type,$m,string $action,array $extra=[]){$url='/api/lifecycle/'.$type.'/'.$m->id;$token=$this->getJson($url)->assertOk()->json('data.preview_token');return $this->postJson($url,[...$extra,'action'=>$action,'confirmed'=>true,'preview_token'=>$token]);}
 public function test_report_trash_revokes_links_and_restores_without_touching_other_months():void{
  $aug=$this->period('2026-08');$sep=$this->period();$oct=$this->period('2026-10');
  $pub=\App\Models\ReportPublication::create(['reporting_period_id'=>$sep->id,'project_id'=>$this->project->id,'version'=>1,'published_by'=>$this->admin->id,'published_at'=>now(),'snapshot'=>['synthetic'=>true],'snapshot_hash'=>hash('sha256','immutable')]);
  DB::table('report_share_links')->insert(['id'=>(string)\Illuminate\Support\Str::ulid(),'publication_id'=>$pub->id,'token_hash'=>hash('sha256','test-token'),'label'=>'Synthetic','expires_at'=>now()->addDay(),'allow_download'=>false,'created_by'=>$this->admin->id,'created_at'=>now(),'updated_at'=>now()]);
  $this->getJson('/api/lifecycle/report/'.$sep->id)->assertJsonPath('data.counts.published_reports',1)->assertJsonPath('data.counts.active_share_links',1);
  $this->change('report',$sep,'trash')->assertOk();$this->assertSoftDeleted('reporting_periods',['id'=>$sep->id]);$this->assertNotNull(DB::table('report_share_links')->first()->revoked_at);$this->assertSame(2,$this->project->reports()->count());
  $this->getJson('/api/trash?type=report')->assertOk()->assertJsonCount(1,'data');$this->change('report',$sep,'restore')->assertOk();$this->assertSame(3,$this->project->reports()->count());$this->assertSame('draft',$aug->fresh()->status);$this->assertSame('draft',$oct->fresh()->status);$this->assertSame(hash('sha256','immutable'),$pub->fresh()->snapshot_hash);$this->assertNotNull(DB::table('report_share_links')->first()->revoked_at);
 }
 public function test_parent_trash_hides_subtree_restore_requires_parents_and_scope_is_enforced():void{
  $report=$this->period();$other=Project::factory()->create(['user_id'=>$this->admin->id,'client_id'=>$this->client->id]);$this->change('project',$this->project,'trash')->assertOk();$this->assertNotNull(Project::find($other->id));$this->assertNotNull(Client::find($this->client->id));$this->assertNull(ReportingPeriod::find($report->id));
  $this->change('report',$report,'restore')->assertConflict();$this->change('project',$this->project,'restore')->assertOk();$this->assertNotNull(ReportingPeriod::find($report->id));
  $this->change('client',$this->client,'archive')->assertOk();$this->assertSame('archived',$this->client->fresh()->status);$this->change('client',$this->client,'restore')->assertOk();
  $this->change('client',$this->client,'trash')->assertOk();$this->assertNull(Project::find($other->id));$this->change('client',$this->client,'restore')->assertOk();
  $outsider=User::factory()->create(['role'=>'admin','is_super_admin'=>false]);$this->actingAs($outsider)->getJson('/api/lifecycle/client/'.$this->client->id)->assertForbidden();$this->getJson('/api/trash?type=client')->assertOk()->assertJsonCount(0,'data');
  $exec=User::factory()->create(['role'=>'seo_executive','is_super_admin'=>false]);$this->project->members()->attach($exec,['assignment_role'=>'member']);$this->actingAs($exec)->getJson('/api/trash')->assertForbidden();$this->change('report',$report,'trash')->assertForbidden();$this->change('report',$report,'reset')->assertOk();
 }
 public function test_stale_confirmations_active_jobs_and_retention_block_destructive_actions():void{
  $report=$this->period();$path='/api/lifecycle/report/'.$report->id;$preview=$this->getJson($path)->json('data');$report->increment('version');$this->postJson($path,['action'=>'trash','confirmed'=>true,'preview_token'=>$preview['preview_token']])->assertConflict();
  $g=\App\Models\ReportGeneration::create(['reporting_period_id'=>$report->id,'requested_by'=>$this->admin->id,'status'=>'queued']);$this->getJson($path)->assertJsonPath('data.busy',true);$this->change('report',$report,'trash')->assertConflict();$g->update(['status'=>'failed']);
  $this->change('report',$report,'trash')->assertOk();$this->change('report',$report,'purge',['confirmation'=>'DELETE '.$report->id])->assertConflict();$this->travel(31)->days();$this->change('report',$report,'purge',['confirmation'=>'wrong'])->assertUnprocessable();$this->change('report',$report,'purge',['confirmation'=>'DELETE '.$report->id])->assertOk();$this->assertDatabaseMissing('reporting_periods',['id'=>$report->id]);$this->assertDatabaseHas('retained_lifecycle_events',['entity_id'=>$report->id,'action'=>'permanently_deleted']);$this->assertNotNull(Project::find($this->project->id));
  $replacement=$this->postJson('/api/projects/'.$this->project->id.'/reports',['title'=>'Recreated synthetic September','kind'=>'monthly','reporting_month'=>'2026-09'])->assertCreated()->json('data.id');$this->assertNotSame($report->id,$replacement);$this->assertSame(0,ReportingPeriod::findOrFail($replacement)->documents()->count());$this->assertSame(1,$this->project->reports()->count());
 }
 public function test_super_admin_only_parent_purge_retains_audit_and_does_not_remove_unrelated_projects():void{
  $report=$this->period();$other=Project::factory()->create(['user_id'=>$this->admin->id,'client_id'=>$this->client->id]);DB::table('project_activity_logs')->insert(['id'=>(string)\Illuminate\Support\Str::ulid(),'project_id'=>$this->project->id,'reporting_period_id'=>$report->id,'actor_id'=>$this->admin->id,'action'=>'synthetic.audit','properties'=>'{}','created_at'=>now()]);
  $this->change('project',$this->project,'trash')->assertOk();$this->travel(31)->days();$this->change('project',$this->project,'purge',['confirmation'=>'DELETE '.$this->project->id])->assertForbidden();$super=User::factory()->create(['is_super_admin'=>true,'role'=>'super_admin']);$this->actingAs($super);$this->change('project',$this->project,'purge',['confirmation'=>'DELETE '.$this->project->id])->assertOk();$this->assertNotNull(Project::find($other->id));$this->assertNotNull(Client::find($this->client->id));$this->assertDatabaseHas('retained_lifecycle_events',['entity_id'=>$this->project->id,'action'=>'retained.project_activity_logs']);
  $this->change('client',$this->client,'trash')->assertOk();$this->travel(31)->days();$this->change('client',$this->client,'purge',['confirmation'=>'DELETE '.$this->client->id])->assertOk();$this->assertDatabaseMissing('clients',['id'=>$this->client->id]);$this->assertDatabaseMissing('projects',['id'=>$other->id]);
 }
 public function test_source_replacement_is_atomic_and_reset_preserves_uploads_and_old_month():void{
  $report=$this->period();$aug=$this->period('2026-08');$base='/api/projects/'.$this->project->id.'/reports/'.$report->id;
  $file=fn($text)=>UploadedFile::fake()->createWithContent('synthetic.csv',"Keyword,Rank\n".$text.",3\n");
  $id=$this->post($base.'/documents',['files'=>[$file('synthetic first')]],['Accept'=>'application/json'])->assertCreated()->json('data.0.id');$doc=ReportDocument::findOrFail($id);$doc->currentRun->update(['status'=>'extracted']);$doc->update(['extraction_status'=>'extracted']);
  $this->post('/api/documents/'.$id.'/replace',['confirmed'=>true,'files'=>[UploadedFile::fake()->createWithContent('bad.pdf','not a pdf')]],['Accept'=>'application/json'])->assertUnprocessable();$this->assertNotNull(ReportDocument::find($id));
  $new=$this->post('/api/documents/'.$id.'/replace',['confirmed'=>true,'files'=>[$file('synthetic second')]],['Accept'=>'application/json'])->assertCreated()->json('data.0.id');$this->assertSoftDeleted('report_documents',['id'=>$id]);$this->assertTrue(Storage::disk('local')->exists($doc->storage_path));$this->assertTrue($report->fresh()->requires_regeneration);
  $replacement=ReportDocument::findOrFail($new);$replacement->currentRun->update(['status'=>'extracted']);$replacement->update(['extraction_status'=>'extracted']);$this->change('report',$report,'reset')->assertOk();$this->assertSame(1,$report->documents()->count());$this->assertSame('draft',$aug->fresh()->status);
  $this->change('document',$replacement,'trash')->assertOk();$this->change('document',$replacement,'restore')->assertOk();$this->assertSame(1,$report->documents()->count());$this->change('document',$replacement,'trash')->assertOk();$this->travel(31)->days();$this->change('document',$replacement,'purge',['confirmation'=>'DELETE '.$new])->assertOk();$this->assertDatabaseHas('private_file_removals',['storage_path'=>$replacement->storage_path]);$this->artisan('workspace:clean-files')->assertExitCode(0);$this->assertFalse(Storage::disk('local')->exists($replacement->storage_path));
 }
 public function test_two_real_connections_competing_to_trash_have_one_success_and_one_conflict():void{
  $report=$this->period();$token=$this->getJson('/api/lifecycle/report/'.$report->id)->assertOk()->json('data.preview_token');
  $file=sys_get_temp_dir().DIRECTORY_SEPARATOR.'lifecycle-race-'.\Illuminate\Support\Str::uuid().'.sqlite';touch($file);$pdo=new \PDO('sqlite:'.$file);$pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA foreign_keys=OFF');$pdo->exec('PRAGMA journal_mode=WAL');$pdo->exec('PRAGMA busy_timeout=5000');
  try{
   foreach(DB::select("SELECT name,type,sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END") as $item){$pdo->exec($item->sql);if($item->type!=='table')continue;foreach(DB::table($item->name)->get() as $row){$data=(array)$row;$cols=implode(',',array_map(fn($c)=>'"'.$c.'"',array_keys($data)));$stmt=$pdo->prepare('INSERT INTO "'.$item->name.'" ('.$cols.') VALUES ('.implode(',',array_fill(0,count($data),'?')).')');$stmt->execute(array_values($data));}}
   $args=[PHP_BINARY,base_path('tests/fixtures/trash-concurrently.php'),$file,$report->id,(string)$this->admin->id,$token,(string)(microtime(true)+1)];$one=new \Symfony\Component\Process\Process($args);$two=new \Symfony\Component\Process\Process($args);$one->start();$two->start();$one->wait();$two->wait();$this->assertTrue($one->isSuccessful(),$one->getErrorOutput());$this->assertTrue($two->isSuccessful(),$two->getErrorOutput());$statuses=[trim($one->getOutput()),trim($two->getOutput())];sort($statuses);$this->assertSame(['200','409'],$statuses);$this->assertSame(1,(int)$pdo->query("select count(*) from retained_lifecycle_events where action='trash'")->fetchColumn());
  }finally{$stmt=null;$pdo=null;foreach([$file,$file.'-wal',$file.'-shm'] as $path)if(is_file($path))unlink($path);}
 }

 public function test_api_guests_receive_401_without_an_accept_header_and_legacy_account_grants_stay_revoked():void{
  $report=$this->period();$viewer=User::factory()->create(['role'=>'client_viewer','is_super_admin'=>false]);$report->viewers()->attach($viewer->id,['shared_by'=>$this->admin->id,'expires_at'=>now()->addDay(),'allow_download'=>false]);
  $this->change('report',$report,'trash')->assertOk();$this->change('report',$report,'restore')->assertOk();$this->assertTrue(now()->gte($report->viewers()->first()->pivot->expires_at));
  $this->app['auth']->forgetGuards();$this->get('/api/dashboard')->assertUnauthorized();
 }

}
