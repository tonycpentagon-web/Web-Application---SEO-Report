<?php
namespace Tests\Feature;
use App\Jobs\{GenerateMonthlyReport,GenerateReportPdf,ProcessReportDocument};
use App\Models\{Project,ReportingPeriod,ReportGeneration,ReportPublication,ReportPdfExport,NormalizedRecord,User};
use App\Services\{DocumentAccess,PythonDocumentProcessor,ReportNormalization,SimplifiedReportSharing};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\{DB,Queue,Storage};
use Tests\TestCase;
class SimplifiedWorkflowTest extends TestCase {
 use RefreshDatabase;
 private User $admin;private User $employee;private Project $project;
 protected function setUp():void {
  parent::setUp();$this->withoutMiddleware(ThrottleRequests::class);Queue::fake();Storage::fake('local');
  $this->admin=User::factory()->create(['is_super_admin'=>true,'role'=>'super_admin']);$this->actingAs($this->admin);
  $client=$this->postJson('/api/clients',['name'=>'Synthetic monthly client','company_name'=>'Synthetic Pharmacy','website'=>'https://synthetic.example'])->assertCreated()->json('data.id');
  $id=$this->postJson('/api/projects',['client_id'=>$client,'name'=>'Synthetic SEO','website'=>'https://synthetic.example'])->assertCreated()->json('data.id');$this->project=Project::findOrFail($id);
  $this->employee=User::factory()->create(['role'=>'seo_executive','is_super_admin'=>false,'managed_by'=>$this->admin->id]);$this->project->members()->attach($this->employee->id,['assignment_role'=>'member']);$this->actingAs($this->employee);
 }
 private function report(string $month='2026-08'):ReportingPeriod {
  $id=$this->postJson('/api/projects/'.$this->project->id.'/reports',['title'=>'Synthetic '.$month,'kind'=>'monthly','reporting_month'=>$month])->assertCreated()->json('data.id');return ReportingPeriod::findOrFail($id);
 }
 private function root(ReportingPeriod $p):string{return '/api/projects/'.$p->project_id.'/reports/'.$p->id;}
 private function generate(ReportingPeriod $p,bool $mixed=true):void {
  $files=[new UploadedFile(base_path('tests/fixtures/monthly/08-ga4.pdf'),'ga4.pdf','application/pdf',null,true)];
  if($mixed)foreach(['search-console.csv'=>'text/csv','keywords.xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','activities.docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','events.png'=>'image/png'] as $name=>$mime)$files[]=new UploadedFile(base_path('tests/fixtures/simplified/'.$name),$name,$mime,null,true);
  $this->post($this->root($p).'/documents',['files'=>$files],['Accept'=>'application/json'])->assertCreated();
  foreach($p->documents()->get() as $doc)(new ProcessReportDocument($doc->current_run_id))->handle(app(PythonDocumentProcessor::class));
  $id=$this->postJson($this->root($p).'/generation')->assertAccepted()->json('data.id');(new GenerateMonthlyReport($id))->handle(app(ReportNormalization::class),app(DocumentAccess::class));$this->assertSame('completed',ReportGeneration::findOrFail($id)->status);
 }
 private function share(ReportingPeriod $p):array {
  $s=$this->getJson($this->root($p).'/sharing')->assertOk()->json('data');$this->assertTrue($s['assessment']['eligible'],json_encode($s['assessment']));
  return $this->postJson($this->root($p).'/delivery/share-report',['version'=>$s['version'],'fingerprint'=>$s['assessment']['fingerprint'],'acknowledge_uncertainty'=>true,'confirm_source_periods'=>true])->assertCreated()->json('data');
 }
 public function test_employee_generates_five_mixed_sources_shares_without_approvals_downloads_and_revokes():void {
  $p=$this->report();$this->generate($p);$d=$this->getJson($this->root($p).'/dashboard?include_provisional=1')->assertOk()->json('data');
  foreach(['report_metrics','keyword_rankings','seo_activities','event_metrics'] as $kind)$this->assertTrue(collect($d['records'])->contains('kind',$kind),'Missing '.$kind);
  $this->assertSame(812,collect($d['kpis'])->firstWhere('metric_key','ga4.total_users')['value']);$this->assertDatabaseCount('record_verifications',0);$this->assertDatabaseCount('report_summary_approvals',0);$this->assertSame(0,NormalizedRecord::where('review_status','approved')->count());
  $summary=$this->getJson($this->root($p).'/summary')->assertOk()->json('data');$summary['sections']['website_traffic']='Source-period analytics values retained; no prior-month comparison is inferred.';
  $this->postJson($this->root($p).'/summary',['revision'=>$summary['revision'],'source_fingerprint'=>$summary['source_fingerprint'],'sections'=>$summary['sections'],'reason'=>'Employee updated the factual narrative before sharing.','confirmed'=>true])->assertOk();
  $s=$this->getJson($this->root($p).'/sharing')->json('data');$this->postJson($this->root($p).'/delivery/share-report',['version'=>$s['version'],'fingerprint'=>$s['assessment']['fingerprint']])->assertUnprocessable();
  $link=$this->share($p);$pub=ReportPublication::findOrFail($link['publication_id']);$this->assertSame(2,$pub->snapshot['schema']);$this->assertNotEmpty($pub->snapshot['disclosures']);$this->assertTrue(collect($pub->snapshot['records'])->contains('provisional',true));$this->assertNotNull($p->fresh()->workspace_archived_at);
  file_put_contents(base_path('../local/review/simplified-browser-fixture.json'),json_encode(['dashboard'=>$d,'snapshot'=>$pub->snapshot,'sharing'=>$this->getJson($this->root($p).'/sharing')->json('data'),'summary'=>$this->getJson($this->root($p).'/summary')->json('data'),'overview'=>$this->getJson('/api/dashboard')->json(),'project'=>$this->project,'report'=>$p->fresh(),'monthly'=>$this->getJson('/api/monthly-reports?tab=history')->json(),'user'=>$this->employee]));
  $this->getJson('/api/monthly-reports?tab=history')->assertJsonPath('total',1);(new GenerateReportPdf($link['export_id']))->handle();$this->assertSame('completed',ReportPdfExport::findOrFail($link['export_id'])->status);$pdf=ReportPdfExport::findOrFail($link['export_id']);file_put_contents(base_path('../local/review/simplified-client.pdf'),Storage::disk('local')->get($pdf->storage_path));
  $token=explode('#',$link['url'])[1];$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$token);$this->app['auth']->forgetGuards();$headers=['X-Report-Token'=>$token];
  $this->getJson('/api/public-report',$headers)->assertOk()->assertJsonPath('data.schema',2)->assertJsonPath('allow_download',true);$this->getJson('/api/public-report?pdf=1',$headers)->assertOk()->assertHeader('Content-Type','application/pdf');$this->getJson('/api/public-report',['X-Report-Token'=>str_repeat('0',64)])->assertNotFound();$this->getJson($this->root($p).'/documents')->assertUnauthorized();
  DB::table('report_summary_approvals')->delete();$this->getJson('/api/public-report',$headers)->assertOk();$this->actingAs($this->employee)->postJson($this->root($p).'/delivery/shares/'.$link['id'].'/revoke',['confirmed'=>true])->assertOk();$this->app['auth']->forgetGuards();$this->getJson('/api/public-report',$headers)->assertNotFound();
  $this->actingAs($this->employee);$secondLink=$this->share($p);
  foreach(['archive','trash','restore'] as $action){$preview=$this->getJson('/api/lifecycle/report/'.$p->id)->assertOk()->json('data.preview_token');$this->postJson('/api/lifecycle/report/'.$p->id,['action'=>$action,'confirmed'=>true,'preview_token'=>$preview])->assertOk();if($action==='archive')$this->assertSame('published',$p->fresh()->status);}
  $this->app['auth']->forgetGuards();$this->getJson('/api/public-report',['X-Report-Token'=>explode('#',$secondLink['url'])[1]])->assertNotFound();
  $this->actingAs($this->employee);$next=$this->report('2026-09');$this->assertNotSame($p->id,$next->id);$this->assertSame(5,$p->documents()->count());$this->assertSame(0,$next->documents()->count());
 }
 public function test_material_period_mismatches_stale_inputs_and_unassigned_users_cannot_share():void {
  $p=$this->report('2026-09');$this->generate($p,false);$s=$this->getJson($this->root($p).'/sharing')->json('data');$this->assertFalse($s['assessment']['eligible']);$this->postJson($this->root($p).'/delivery/share-report',['version'=>1,'fingerprint'=>$s['assessment']['fingerprint'],'acknowledge_uncertainty'=>true])->assertConflict();$this->assertDatabaseCount('report_publications',0);
  $outsider=User::factory()->create(['role'=>'seo_executive','is_super_admin'=>false]);$this->actingAs($outsider)->getJson($this->root($p).'/sharing')->assertNotFound();$this->postJson($this->root($p).'/delivery/share-report',['version'=>1,'fingerprint'=>str_repeat('a',64)])->assertNotFound();
  $this->actingAs($this->employee);$aug=$this->report();$this->generate($aug,false);$this->postJson($this->root($aug).'/delivery/share-report',['version'=>1,'fingerprint'=>str_repeat('a',64),'acknowledge_uncertainty'=>true])->assertConflict();$aug->update(['requires_regeneration'=>true]);$this->assertFalse(app(SimplifiedReportSharing::class)->assess($aug->fresh())['eligible']);
 }
 public function test_conflicts_and_explicit_wrong_client_sources_cannot_be_acknowledged_away():void {
  $p=$this->report();$this->generate($p,false);
  foreach(['wrong-client'=>"Client: Another Company\nGoogle Analytics Report - August 2026\nMetric,Value\nTotal users,812\n",'conflict'=>"Google Analytics Report - August 2026\nMetric,Value\nTotal users,999\n"] as $label=>$text){

   $this->post($this->root($p).'/documents',['files'=>[UploadedFile::fake()->createWithContent($label.'.csv',$text)]],['Accept'=>'application/json'])->assertCreated();
   $doc=$p->documents()->where('original_filename',$label.'.csv')->firstOrFail();(new ProcessReportDocument($doc->current_run_id))->handle(app(PythonDocumentProcessor::class));
   $id=$this->postJson($this->root($p).'/generation')->assertAccepted()->json('data.id');(new GenerateMonthlyReport($id))->handle(app(ReportNormalization::class),app(DocumentAccess::class));
   $state=$this->getJson($this->root($p).'/sharing')->assertOk()->json('data');$this->assertFalse($state['assessment']['eligible'],json_encode($state));
   $this->assertStringContainsString($label==='conflict'?'Conflicting':'client',implode(' ', $state['assessment']['blockers']));
   $this->postJson($this->root($p).'/delivery/share-report',['version'=>$state['version'],'fingerprint'=>$state['assessment']['fingerprint'],'acknowledge_uncertainty'=>true])->assertConflict();
   if($label==='wrong-client'){ $preview=$this->getJson('/api/lifecycle/document/'.$doc->id)->assertOk()->json('data.preview_token');$this->deleteJson('/api/documents/'.$doc->id,['confirmed'=>true,'preview_token'=>$preview])->assertOk(); }
  }
  $this->assertDatabaseCount('report_publications',0);
 }
 public function test_second_client_isolated_snapshot_expiration_and_duplicate_share():void {
  $first=$this->report();$this->generate($first,false);$one=$this->share($first);
  $hash=ReportPublication::findOrFail($one['publication_id'])->snapshot_hash;
  $again=$this->share($first);$this->assertSame($one['publication_id'],$again['publication_id']);$this->assertNotSame($one['url'],$again['url']);
  $this->actingAs($this->admin);
  $client=$this->postJson('/api/clients',['name'=>'Second Synthetic Client','website'=>'https://second.example'])->assertCreated()->json('data.id');
  $project=$this->postJson('/api/projects',['client_id'=>$client,'name'=>'Second SEO','website'=>'https://second.example'])->assertCreated()->json('data.id');
  $this->project=Project::findOrFail($project);$this->project->members()->attach($this->employee->id,['assignment_role'=>'member']);$this->actingAs($this->employee);
  $second=$this->report();$this->generate($second,false);$two=$this->share($second);
  $this->assertSame($hash,ReportPublication::findOrFail($one['publication_id'])->snapshot_hash);
  $this->app['auth']->forgetGuards();
  $this->getJson('/api/public-report',['X-Report-Token'=>explode('#',$two['url'])[1]])->assertOk()->assertJsonPath('data.project.client_name','Second Synthetic Client');
  $this->getJson('/api/public-report',['X-Report-Token'=>explode('#',$one['url'])[1]])->assertOk()->assertJsonPath('data.project.client_name','Synthetic monthly client');
  $this->travel(31)->days();$this->getJson('/api/public-report',['X-Report-Token'=>explode('#',$one['url'])[1]])->assertNotFound();$this->travelBack();
 }

 public function test_updated_publication_preserves_old_snapshot_links_and_validates_current_inputs():void {
  $p=$this->report();$this->generate($p,false);$one=$this->share($p);$original=ReportPublication::findOrFail($one['publication_id']);$hash=$original->snapshot_hash;
  $state=$this->getJson($this->root($p).'/sharing')->assertOk()->json('data');
  $input=['version'=>$state['version'],'fingerprint'=>$state['assessment']['fingerprint'],'new_publication'=>true,'acknowledge_uncertainty'=>true,'confirm_source_periods'=>true];
  $this->postJson($this->root($p).'/delivery/share-report',array_replace($input,['fingerprint'=>str_repeat('0',64)]))->assertConflict();
  $two=$this->postJson($this->root($p).'/delivery/share-report',$input)->assertCreated()->json('data');
  $latest=ReportPublication::findOrFail($two['publication_id']);$this->assertNotSame($original->id,$latest->id);$this->assertSame($original->version+1,$latest->version);$this->assertSame($hash,$original->fresh()->snapshot_hash);$this->assertSame('deduplicated_publication_records',$latest->snapshot['summary_basis']);
  $this->app['auth']->forgetGuards();
  foreach([$one,$two] as $link)$this->getJson('/api/public-report',['X-Report-Token'=>explode('#',$link['url'])[1]])->assertOk();
  $this->actingAs($this->employee);$p->refresh()->update(['requires_regeneration'=>true]);$state=$this->getJson($this->root($p).'/sharing')->json('data');
  $this->postJson($this->root($p).'/delivery/share-report',array_replace($input,['version'=>$state['version'],'fingerprint'=>$state['assessment']['fingerprint']]))->assertConflict();
  $this->assertDatabaseCount('report_publications',2);
 }

 public function test_preparing_updated_generation_keeps_published_link_available_and_preview_scoped():void {
  $p=$this->report();$this->generate($p,false);$link=$this->share($p);$hash=ReportPublication::findOrFail($link['publication_id'])->snapshot_hash;
  $this->getJson($this->root($p).'/presentation')->assertOk()->assertJsonPath('data.draft',true);
  $this->postJson($this->root($p).'/generation')->assertForbidden();
  $id=$this->postJson($this->root($p).'/generation',['prepare_updated_publication'=>true,'confirmed'=>true])->assertAccepted()->json('data.id');
  (new GenerateMonthlyReport($id,true))->handle(app(ReportNormalization::class),app(DocumentAccess::class));
  $this->assertSame('completed',ReportGeneration::findOrFail($id)->status);$this->assertSame('published',$p->fresh()->status);$this->assertSame($hash,ReportPublication::findOrFail($link['publication_id'])->snapshot_hash);
  $outsider=User::factory()->create(['role'=>'seo_executive','is_super_admin'=>false]);$this->actingAs($outsider)->getJson($this->root($p).'/presentation')->assertNotFound();$this->postJson($this->root($p).'/generation',['prepare_updated_publication'=>true,'confirmed'=>true])->assertNotFound();
  $this->app['auth']->forgetGuards();$this->getJson('/api/public-report',['X-Report-Token'=>explode('#',$link['url'])[1]])->assertOk();$this->getJson($this->root($p).'/presentation')->assertUnauthorized();
 }

 public function test_actual_cross_month_coverage_requires_explicit_current_source_assignment_and_never_allows_a_wholly_different_month():void {
  foreach([['2026-08','Jul 29 2026 - Aug 27 2026',200],['2026-09','Aug 1 2026 - Aug 31 2026',422]] as [$month,$range,$expected]){
   $p=$this->report($month);$this->post($this->root($p).'/documents',['files'=>[UploadedFile::fake()->createWithContent('coverage.csv',"Reporting period: ".$range."\nGoogle Analytics\nMetric,Value\nTotal users,812\nNew users,700\nViews,900\n")]],['Accept'=>'application/json'])->assertCreated();
   foreach($p->documents()->get() as $doc)(new ProcessReportDocument($doc->current_run_id))->handle(app(PythonDocumentProcessor::class));
   $id=$this->postJson($this->root($p).'/generation')->assertAccepted()->json('data.id');(new GenerateMonthlyReport($id))->handle(app(ReportNormalization::class),app(DocumentAccess::class));
   $state=$this->getJson($this->root($p).'/sharing')->assertOk()->json('data');$this->assertFalse($state['assessment']['eligible']);$this->assertNotEmpty($state['assessment']['period_mismatches']);
   $input=['version'=>$state['version'],'fingerprint'=>$state['assessment']['fingerprint'],'document_ids'=>[$doc->id],'confirmed'=>true];
   $this->postJson($this->root($p).'/sharing/source-coverage',array_replace($input,['confirmed'=>false]))->assertUnprocessable();
   $this->postJson($this->root($p).'/sharing/source-coverage',array_replace($input,['fingerprint'=>str_repeat('0',64)]))->assertConflict();
   $this->postJson($this->root($p).'/sharing/source-coverage',$input)->assertStatus($expected);
   if($expected===200){$state=$this->getJson($this->root($p).'/sharing')->json('data');$this->assertTrue($state['assessment']['eligible'],json_encode($state['assessment']));$this->assertTrue($state['assessment']['source_periods'][0]['coverage_accepted']);$this->assertDatabaseCount('record_verifications',0);$link=$this->share($p);$this->assertTrue(ReportPublication::findOrFail($link['publication_id'])->snapshot['source_periods'][0]['coverage_accepted']);}
  }
 }

}
