<?php
require __DIR__.'/../../backend/vendor/autoload.php';$app=require __DIR__.'/../../backend/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$info=json_decode(file_get_contents(__DIR__.'/hanns-live-session.json'),true);Illuminate\Support\Facades\DB::table('sessions')->where('id',$info['session_id'])->delete();file_put_contents(__DIR__.'/hanns-live-session.json',json_encode(['revoked'=>true,'purpose'=>'Completed local browser verification']));echo 'Temporary verification session revoked. Existing employee sessions unchanged.';
