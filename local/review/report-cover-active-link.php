<?php
require __DIR__.'/../../backend/vendor/autoload.php';$app=require __DIR__.'/../../backend/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$row=Illuminate\Support\Facades\DB::table('report_share_links')->whereNull('revoked_at')->where('expires_at','>',now())->first();
if(!$row||!$row->encrypted_token)throw new RuntimeException('No active copyable public report.');
$token=Illuminate\Support\Facades\Crypt::decryptString($row->encrypted_token);
file_put_contents(__DIR__.'/report-cover-active-link.json',json_encode(['token'=>$token]));echo 'Current active public token read privately for verification only.';
