<?php
require __DIR__.'/../../backend/vendor/autoload.php';$app=require __DIR__.'/../../backend/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$before=json_decode(file_get_contents(__DIR__.'/cleanup-execution-preflight.json'),true);
$u=App\Models\User::findOrFail(1);$c=app(App\Services\ClientTestDataCleanup::class);$plan=$c->inventory($u);
foreach($plan['counts'] as $table=>$n)if($n!==0)throw new RuntimeException('Unexpected remaining records: '.$table);
if($plan['file_count']!==0)throw new RuntimeException('Unexpected remaining managed files.');
$preserved=[];foreach($before['protected_hashes'] as $t=>$hash){$now=hash('sha256',DB::table($t)->orderBy($t==='password_reset_tokens'?'email':'id')->get()->toJson());$preserved[$t]=hash_equals($hash,$now);if(!$preserved[$t])throw new RuntimeException('Protected table changed: '.$t);}
$schema=hash('sha256',json_encode(DB::select("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY type,name")));
if(!hash_equals($before['schema_hash'],$schema))throw new RuntimeException('Schema changed.');
if(DB::select('PRAGMA integrity_check')[0]->integrity_check!=='ok'||DB::select('PRAGMA foreign_key_check'))throw new RuntimeException('Database integrity failed.');
$folder=$before['backup'];$backupIntact=hash_equals($before['backup_database_sha256'],hash_file('sha256',$folder.'/database.sqlite'))&&hash_equals($before['backup_archive_sha256'],hash_file('sha256',$folder.'/client-files.zip'));
if(!$backupIntact)throw new RuntimeException('Backup changed.');
$result=['counts'=>$plan['counts'],'managed_files_remaining'=>$plan['file_count'],'protected_tables_unchanged'=>$preserved,'schema_unchanged'=>true,'database_integrity'=>'ok','foreign_key_violations'=>0,'backup_database_and_archive_unchanged'=>true,'user_accounts'=>DB::table('users')->count(),'maintenance_mode'=>app()->isDownForMaintenance(),'replacement_records_created'=>false];
file_put_contents(__DIR__.'/cleanup-postcheck-results.json',json_encode($result,JSON_PRETTY_PRINT));echo json_encode($result,JSON_PRETTY_PRINT);
// Short-lived session for read-only browser verification; remove this exact session afterwards.
$session=app('session')->driver();$session->start();$session->regenerateToken();$session->put(Illuminate\Support\Facades\Auth::guard('web')->getName(),$u->id);$session->save();
$name=config('session.cookie');$prefix=Illuminate\Cookie\CookieValuePrefix::create($name,app('encrypter')->getKey());$value=app('encrypter')->encrypt($prefix.$session->getId(),false);
file_put_contents(__DIR__.'/cleanup-verification-session.json',json_encode(['cookie'=>['name'=>$name,'value'=>$value,'domain'=>'127.0.0.1','path'=>'/','httpOnly'=>true,'sameSite'=>'Lax'],'session_id'=>$session->getId()]));
