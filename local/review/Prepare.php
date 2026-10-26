<?php
// Creates an isolated review snapshot; never mutates the working database.
require dirname(__DIR__,2).'/backend/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$dir=dirname(__DIR__,2).'/.tools/https-review';
if(file_exists($dir.'/review.sqlite'))throw new RuntimeException('Review snapshot already exists. Reuse it or choose a fresh state directory.');
if(config('database.default')!=='sqlite')throw new RuntimeException('This snapshot helper requires SQLite; working database unchanged.');
$pdo=DB::connection()->getPdo();$pdo->exec('VACUUM INTO '.$pdo->quote($dir.'/review.sqlite'));
$source=Illuminate\Support\Facades\Storage::disk('local')->path('');
$dest=$dir.'/storage/app/private';
@mkdir($dest,0700,true);
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
foreach($iterator as $file){if($file->isLink())throw new RuntimeException('Private storage contains a symlink.');$target=$dest.'/'.substr($file->getPathname(),strlen(rtrim($source,'/\\'))+1);if($file->isDir()){@mkdir($target,0700,true);}elseif(!copy($file->getPathname(),$target)){throw new RuntimeException('Snapshot file copy failed.');}}
foreach(['framework/sessions','framework/cache/data','framework/views','logs'] as $folder)@mkdir($dir.'/storage/'.$folder,0700,true);
DB::disconnect();config(['database.connections.sqlite.database'=>$dir.'/review.sqlite']);DB::purge();
foreach(['sessions','password_reset_tokens','personal_access_tokens','jobs','failed_jobs','job_batches','cache','cache_locks','worker_heartbeats'] as $table)if(Illuminate\Support\Facades\Schema::hasTable($table))DB::table($table)->delete();
DB::table('users')->update(['is_active'=>false,'remember_token'=>null]);
DB::table('report_share_links')->update(['revoked_at'=>now(),'encrypted_token'=>null]);
$password=bin2hex(random_bytes(20));
$user=new App\Models\User(['name'=>'Temporary SEO Team Lead','email'=>'team-lead@review.invalid','password'=>$password]);
$user->role='seo_executive';$user->is_active=true;$user->is_super_admin=false;$user->save();
$report=App\Models\ReportingPeriod::whereHas('project',fn($q)=>$q->where('status','active'))->withCount('documents')->orderByDesc('documents_count')->firstOrFail();
$project=$report->project;$project->members()->syncWithoutDetaching([$user->id=>['assignment_role'=>'executive']]);
$assets=DB::table('metric_source_references as s')->join('normalized_records as r','r.id','=','s.normalized_record_id')->join('document_assets as a','a.id','=','s.asset_id')->where('r.reporting_period_id',$report->id)->where('r.kind','ai_visibility_evidence')->where('r.active',true)->whereIn('a.mime_type',['image/png','image/jpeg','image/webp'])->select('s.document_id','s.asset_id')->distinct()->get()->map(fn($s)=>'/api/documents/'.$s->document_id.'/assets/'.$s->asset_id)->all();
$state=['project'=>$project->id,'report'=>$report->id,'client'=>$project->client_id,'assets'=>$assets,'created_at'=>date(DATE_ATOM)];
file_put_contents($dir.'/snapshot.json',json_encode($state,JSON_PRETTY_PRINT));
file_put_contents($dir.'/credentials.json',json_encode(['email'=>$user->email,'password'=>$password],JSON_PRETTY_PRINT));
file_put_contents($dir.'/ACCESS.txt',"Temporary review copy only. Share this password privately with the authorized Team Lead.\r\nEmail: ".$user->email."\r\nPassword: ".$password."\r\n");
echo json_encode(['snapshot_ready'=>true,'project'=>$project->id,'report'=>$report->id,'allowed_evidence_images'=>count($assets)]);
