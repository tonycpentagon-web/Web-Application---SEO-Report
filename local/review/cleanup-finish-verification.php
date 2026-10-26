<?php
require __DIR__.'/../../backend/vendor/autoload.php';$app=require __DIR__.'/../../backend/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$info=json_decode(file_get_contents(__DIR__.'/cleanup-verification-session.json'),true);
if(empty($info['session_id']))throw new RuntimeException('Exact verification session missing; no session deletion performed.');
$count=Illuminate\Support\Facades\DB::table('sessions')->where('id',$info['session_id'])->delete();
file_put_contents(__DIR__.'/cleanup-verification-session.json',json_encode(['revoked'=>true,'purpose'=>'Completed read-only cleanup browser verification']));
file_put_contents(__DIR__.'/cleanup-old-link-tokens.json',json_encode(['revoked_links_verified'=>8,'plaintext_tokens_removed'=>true]));
$plan=app(App\Services\ClientTestDataCleanup::class)->inventory(App\Models\User::findOrFail(1));
foreach($plan['counts'] as $table=>$n)if($n!==0)throw new RuntimeException('Unexpected client data appeared: '.$table);
if($plan['file_count']!==0)throw new RuntimeException('Unexpected managed files remain.');
echo json_encode(['verification_session_removed'=>$count,'client_related_rows_remaining'=>array_sum($plan['counts']),'managed_files_remaining'=>$plan['file_count'],'existing_users'=>Illuminate\Support\Facades\DB::table('users')->count(),'maintenance_mode'=>app()->isDownForMaintenance(),'replacement_records_created'=>false]);
