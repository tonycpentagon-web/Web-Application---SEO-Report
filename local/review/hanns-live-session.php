<?php
require __DIR__.'/../../backend/vendor/autoload.php';$app=require __DIR__.'/../../backend/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$p=App\Models\ReportingPeriod::findOrFail('01m5cc50zfzk56dwfc9ft9erb6');$u=App\Models\User::findOrFail(1);abort_unless(app(App\Services\WorkspaceAccess::class)->workReports($u,$p->project),403);
$session=app('session')->driver();$session->start();$session->regenerateToken();$session->put(Illuminate\Support\Facades\Auth::guard('web')->getName(),$u->id);$session->save();
$name=config('session.cookie');$prefix=Illuminate\Cookie\CookieValuePrefix::create($name,app('encrypter')->getKey());$value=app('encrypter')->encrypt($prefix.$session->getId(),false);
file_put_contents(__DIR__.'/hanns-live-session.json',json_encode(['cookie'=>['name'=>$name,'value'=>$value,'domain'=>'127.0.0.1','path'=>'/','httpOnly'=>true,'sameSite'=>'Lax'],'session_id'=>$session->getId(),'project_id'=>$p->project_id,'report_id'=>$p->id]));echo 'Short-lived local verification session prepared privately. No password, account or permission changes.';
