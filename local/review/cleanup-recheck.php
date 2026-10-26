<?php
require __DIR__.'/../../backend/vendor/autoload.php';
$app=require __DIR__.'/../../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c=app(App\Services\ClientTestDataCleanup::class);$u=App\Models\User::findOrFail(1);
$folder='D:/pentagon-insight-flow-backups/20261022-102130-01m5hj2mkk47gp56mmxc7g9rdg';
$m=$c->manifest($folder);$plan=$c->inventory($u);
if($m['plan']['fingerprint']!==$plan['fingerprint'] || $m['plan']['identity']!==$plan['identity'] || $plan['busy'])throw new RuntimeException('STOP: reviewed environment or dataset no longer matches.');
$v=$c->verify($folder);
$links=[];foreach(Illuminate\Support\Facades\DB::table('report_share_links')->get() as $row){
 if(empty($row->encrypted_token))throw new RuntimeException('Cannot preserve a token for all-link verification.');
 $token=Illuminate\Support\Facades\Crypt::decryptString($row->encrypted_token);
 if(!hash_equals($row->token_hash,hash('sha256',$token)))throw new RuntimeException('Token audit hash mismatch.');
 $links[]=['id'=>$row->id,'token'=>$token];
}
$protected=[];foreach(['users','migrations','password_reset_tokens','personal_access_tokens'] as $table)$protected[$table]=hash('sha256',Illuminate\Support\Facades\DB::table($table)->orderBy($table==='password_reset_tokens'?'email':'id')->get()->toJson());
$schema=hash('sha256',json_encode(Illuminate\Support\Facades\DB::select("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY type,name")));
file_put_contents(__DIR__.'/cleanup-old-link-tokens.json',json_encode($links));
$result=['matched'=>true,'fingerprint'=>$plan['fingerprint'],'identity'=>$plan['identity'],'counts'=>$plan['counts'],'file_count'=>$plan['file_count'],'file_bytes'=>$plan['file_bytes'],'backup'=>$folder,'backup_database_sha256'=>$m['database_sha256'],'backup_archive_sha256'=>$m['archive_sha256'],'backup_verified'=>$v['verified'],'restored_files'=>$v['verified_files'],'old_links_saved_for_verification'=>count($links),'protected_hashes'=>$protected,'schema_hash'=>$schema];
file_put_contents(__DIR__.'/cleanup-execution-preflight.json',json_encode($result,JSON_PRETTY_PRINT));
echo json_encode($result,JSON_PRETTY_PRINT);
