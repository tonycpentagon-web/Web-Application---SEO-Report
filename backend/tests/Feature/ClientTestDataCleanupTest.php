<?php
namespace Tests\Feature;
use App\Models\{User,Client,Project,ReportingPeriod,ReportPublication};
use App\Services\ClientTestDataCleanup;
use App\Jobs\Middleware\ClientDatasetGuard;
use Illuminate\Support\Facades\{Artisan,DB,Queue,Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientTestDataCleanupTest extends TestCase
{
    private User $admin;
    private ClientTestDataCleanup $cleanup;
    private string $root;
    protected function setUp():void
    {
        parent::setUp();
        // Never migrate/reset the live database. Each test owns a new in-memory connection.
        $this->assertSame(':memory:',DB::connection()->getDatabaseName());
        Artisan::call('migrate',['--force'=>true]);
        Storage::fake('local');Queue::fake();
        $this->admin=User::factory()->create(['role'=>'super_admin','is_super_admin'=>true,'is_active'=>true]);
        $this->cleanup=app(ClientTestDataCleanup::class);
        $this->root=sys_get_temp_dir().'/pentagon-cleanup-test-'.Str::ulid();
        // Avoid changing the running app's maintenance file from isolated tests.
        Artisan::shouldReceive('call')->with('down')->andReturn(0);
        Artisan::shouldReceive('call')->with('up')->andReturn(0);
    }
    private function dataset():array
    {
        $client=new Client(['name'=>'Disposable client','status'=>'active']);
        $client->forceFill(['user_id'=>$this->admin->id,'identity_key'=>hash('sha256',Str::random())])->save();
        $project=Project::factory()->create(['client_id'=>$client->id,'user_id'=>$this->admin->id]);
        $report=$project->reports()->create(['title'=>'Disposable August','kind'=>'monthly','reporting_month'=>'2026-08','start_date'=>'2026-08-01','end_date'=>'2026-08-31','status'=>'draft','created_by'=>$this->admin->id,'updated_by'=>$this->admin->id]);
        $publication=ReportPublication::create(['reporting_period_id'=>$report->id,'project_id'=>$project->id,'version'=>1,'published_by'=>$this->admin->id,'published_at'=>now(),'snapshot'=>['schema'=>2],'snapshot_hash'=>hash('sha256','disposable')]);
        $token=str_repeat('a',64);
        DB::table('report_share_links')->insert(['id'=>(string)Str::ulid(),'publication_id'=>$publication->id,'token_hash'=>hash('sha256',$token),'label'=>'Disposable','expires_at'=>now()->addDay(),'allow_download'=>false,'created_by'=>$this->admin->id,'created_at'=>now(),'updated_at'=>now()]);
        $file='report-documents/'.Str::lower((string)Str::ulid()).'/orphan.png';Storage::disk('local')->put($file,'disposable managed evidence');
        $project->delete(); // Include a subtree hidden by soft-delete scopes.
        return compact('client','project','report','token','file');
    }
    private function denied(callable $call,string $message):void
    {
        try{$call();$this->fail('Expected cleanup refusal');}catch(\RuntimeException $e){$this->assertStringContainsString($message,$e->getMessage());}
    }
    public function test_dry_run_is_read_only_and_privileged_local_guard_fails_closed():void
    {
        $d=$this->dataset();$plan=$this->cleanup->inventory($this->admin);
        $this->assertSame(1,$plan['counts']['clients']);$this->assertSame(1,$plan['states']['projects']['trashed']);
        $this->assertSame(1,$plan['file_count']);$this->assertDatabaseCount('report_share_links',1);Storage::disk('local')->assertExists($d['file']);
        $employee=User::factory()->create(['is_super_admin'=>false]);
        $this->denied(fn()=>$this->cleanup->inventory($employee),'Super Admin');
        $this->app->instance('env','production');
        $this->denied(fn()=>$this->cleanup->inventory($this->admin),'local development');
    }
    public function test_verified_backup_cleanup_preserves_accounts_unrelated_files_and_new_data_on_repeat():void
    {
        $d=$this->dataset();$employee=User::factory()->create(['is_super_admin'=>false]);
        Storage::disk('local')->put('health-probes/preserve.txt','shared app file');
        $users=DB::table('users')->orderBy('id')->get()->toJson();$migrations=DB::table('migrations')->get()->toJson();
        $b=$this->cleanup->backup($this->admin,$this->root);
        $this->assertTrue($b['verification']['verified']);$this->assertSame(1,$b['verification']['verified_files']);
        $this->assertSame(2,$b['verification']['restored_table_counts']['users']);
        $this->denied(fn()=>$this->cleanup->execute($this->admin,$b['folder'],''),'confirmation');
        $result=$this->cleanup->execute($this->admin,$b['folder'],$b['confirmation']);
        $this->assertTrue($result['successful']);$this->assertSame(1,$result['deleted_records']['clients']);
        foreach(ClientTestDataCleanup::TABLES as $table)$this->assertDatabaseCount($table,0);
        $this->assertSame($users,DB::table('users')->orderBy('id')->get()->toJson());$this->assertSame($migrations,DB::table('migrations')->get()->toJson());
        Storage::disk('local')->assertMissing($d['file']);Storage::disk('local')->assertExists('health-probes/preserve.txt');
        $this->getJson('/api/public-report',['X-Report-Token'=>$d['token']])->assertNotFound();$this->assertSame([],DB::select('PRAGMA foreign_key_check'));
        $new=$this->dataset();$again=$this->cleanup->execute($this->admin,$b['folder'],$b['confirmation']);
        $this->assertTrue($again['successful']);$this->assertDatabaseCount('clients',1);Storage::disk('local')->assertExists($new['file']);
    }
    public function test_changed_dataset_and_corrupted_backup_prevent_any_deletion():void
    {
        $d=$this->dataset();$b=$this->cleanup->backup($this->admin,$this->root);
        $d['client']->update(['name'=>'Changed after approval']);
        $this->denied(fn()=>$this->cleanup->execute($this->admin,$b['folder'],$b['confirmation']),'changed');
        $this->assertDatabaseCount('clients',1);
        file_put_contents($b['folder'].'/client-files.zip','corrupted');
        $this->denied(fn()=>$this->cleanup->execute($this->admin,$b['folder'],$b['confirmation']),'checksum');
        $this->assertDatabaseCount('clients',1);Storage::disk('local')->assertExists($d['file']);
    }
    public function test_empty_cleanup_backup_is_valid_and_unsafe_paths_are_refused():void
    {
        $b=$this->cleanup->backup($this->admin,$this->root);$this->assertSame(0,$b['verification']['verified_files']);
        $this->assertTrue($this->cleanup->execute($this->admin,$b['folder'],$b['confirmation'])['successful']);
        foreach(['../.env','client-logos/../.env','C:/Windows/test','//server/share/file','report-documents/arbitrary/file'] as $path)$this->denied(fn()=>$this->cleanup->safePath($path),'Unsafe');
    }
    public function test_cleanup_of_generated_report_removes_foreign_key_cycles_and_public_access():void
    {
        $d=$this->dataset();$d['project']->restore();$this->actingAs($this->admin);
        $period=$d['report'];$base='/api/projects/'.$period->project_id.'/reports/'.$period->id;
        $file=new \Illuminate\Http\UploadedFile(base_path('tests/fixtures/monthly/08-ga4.pdf'),'ga4.pdf','application/pdf',null,true);
        $this->post($base.'/documents',['files'=>[$file]],['Accept'=>'application/json'])->assertCreated();
        foreach($period->documents()->get() as $doc)(new \App\Jobs\ProcessReportDocument($doc->current_run_id))->handle(app(\App\Services\PythonDocumentProcessor::class));
        $id=$this->postJson($base.'/generation')->assertAccepted()->json('data.id');
        (new \App\Jobs\GenerateMonthlyReport($id))->handle(app(\App\Services\ReportNormalization::class),app(\App\Services\DocumentAccess::class));
        $this->assertDatabaseHas('report_generations',['id'=>$id,'status'=>'completed']);
        $this->assertGreaterThan(0,DB::table('normalized_records')->count());
        $state=$this->getJson($base.'/sharing')->assertOk()->json('data');
        $link=$this->postJson($base.'/delivery/share-report',['version'=>$state['version'],'fingerprint'=>$state['assessment']['fingerprint'],'acknowledge_uncertainty'=>true,'confirm_source_periods'=>true])->assertCreated()->json('data');
        $token=explode('#',$link['url'])[1];$this->app['auth']->forgetGuards();
        $this->getJson('/api/public-report',['X-Report-Token'=>$token])->assertOk();
        $b=$this->cleanup->backup($this->admin,$this->root);
        $result=$this->cleanup->execute($this->admin,$b['folder'],$b['confirmation']);
        $this->assertTrue($result['successful']);
        foreach(ClientTestDataCleanup::TABLES as $table)$this->assertDatabaseCount($table,0);
        $this->assertSame(0,$this->cleanup->inventory($this->admin)['file_count']);
        $this->getJson('/api/public-report',['X-Report-Token'=>$token])->assertNotFound();
        $this->actingAs($this->admin)->getJson('/api/dashboard')->assertOk();
    }
    public function test_only_report_jobs_and_client_specific_retained_audits_are_selected():void
    {
        $this->dataset();
        foreach(['App\\Jobs\\ProcessReportDocument','App\\Jobs\\UnrelatedMaintenance'] as $name){
            $payload=json_encode(['data'=>['commandName'=>$name]]);
            DB::table('jobs')->insert(['queue'=>'documents','payload'=>$payload,'attempts'=>0,'reserved_at'=>null,'available_at'=>time(),'created_at'=>time()]);
            DB::table('failed_jobs')->insert(['uuid'=>(string)Str::uuid(),'connection'=>'database','queue'=>'documents','payload'=>$payload,'exception'=>'Synthetic failure']);
        }
        foreach(['client','administration'] as $type)DB::table('retained_lifecycle_events')->insert(['id'=>(string)Str::ulid(),'entity_type'=>$type,'entity_id'=>(string)Str::ulid(),'actor_id'=>$this->admin->id,'action'=>'synthetic','metadata'=>'{}','created_at'=>now()]);
        $b=$this->cleanup->backup($this->admin,$this->root);
        $this->assertSame(1,$b['manifest']['plan']['counts']['jobs']);
        $result=$this->cleanup->execute($this->admin,$b['folder'],$b['confirmation']);
        $this->assertTrue($result['successful']);
        $this->assertDatabaseCount('jobs',1);$this->assertDatabaseCount('failed_jobs',1);
        $this->assertDatabaseCount('retained_lifecycle_events',1);
        $this->assertDatabaseHas('retained_lifecycle_events',['entity_type'=>'administration']);
    }
    public function test_processing_or_reserved_jobs_block_backup_and_changes_to_files_block_deletion():void
    {
        $d=$this->dataset();
        $id=DB::table('jobs')->insertGetId(['queue'=>'documents','payload'=>'{}','attempts'=>1,'reserved_at'=>time(),'available_at'=>time(),'created_at'=>time()]);
        $this->denied(fn()=>$this->cleanup->backup($this->admin,$this->root),'Processing is active');
        DB::table('jobs')->where('id',$id)->delete();$b=$this->cleanup->backup($this->admin,$this->root);
        Storage::disk('local')->put($d['file'],'Changed after backup');
        $this->denied(fn()=>$this->cleanup->execute($this->admin,$b['folder'],$b['confirmation']),'changed');
        $this->assertDatabaseCount('clients',1);Storage::disk('local')->assertExists($d['file']);
    }
    public function test_partial_file_failure_is_reported_and_retry_does_not_repeat_database_deletion():void
    {
        $d=$this->dataset();$b=$this->cleanup->backup($this->admin,$this->root);
        $failure=new class extends ClientTestDataCleanup {
            public function safePath(string $relative,bool $mustExist=false):string {
                if(DB::table('clients')->count()===0 && str_ends_with($relative,'orphan.png'))throw new \RuntimeException('Synthetic filesystem failure');
                return parent::safePath($relative,$mustExist);
            }
        };
        $result=$failure->execute($this->admin,$b['folder'],$b['confirmation']);
        $this->assertFalse($result['successful']);$this->assertTrue($result['database_committed']);
        $this->assertCount(1,$result['pending_files']);$this->assertCount(1,$result['failures']);
        $this->assertDatabaseCount('clients',0);Storage::disk('local')->assertExists($d['file']);
        $retry=$this->cleanup->execute($this->admin,$b['folder'],$b['confirmation']);
        $this->assertTrue($retry['successful']);$this->assertCount(1,$retry['deleted_files']);
        Storage::disk('local')->assertMissing($d['file']);
    }
    public function test_job_and_cleanup_locks_exclude_each_other():void
    {
        $job=new class{public bool $released=false;public function release($delay){$this->released=$delay===10;}};
        $lock=$this->cleanup->lock();$ran=false;
        (new ClientDatasetGuard)->handle($job,function()use(&$ran){$ran=true;});
        $this->assertTrue($job->released);$this->assertFalse($ran);flock($lock,LOCK_UN);fclose($lock);
        (new ClientDatasetGuard)->handle($job,function(){ $this->denied(fn()=>$this->cleanup->lock(),'dataset lock'); });
    }
}
