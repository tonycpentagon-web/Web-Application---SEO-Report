<?php
$trial=__DIR__.'/hanns-period-trial.sqlite';$_ENV['DB_DATABASE']=$_SERVER['DB_DATABASE']=$trial;putenv('DB_DATABASE='.$trial);
require __DIR__.'/../../backend/vendor/autoload.php';$app=require __DIR__.'/../../backend/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(realpath(Illuminate\Support\Facades\DB::connection()->getDatabaseName())!==realpath($trial))throw new RuntimeException('Trial database isolation failed');
$p=App\Models\ReportingPeriod::findOrFail('01m5cc50zfzk56dwfc9ft9erb6');$u=App\Models\User::findOrFail(1);
$v=app(App\Services\EvidenceValidation::class)->evaluate($p,$u);$a=app(App\Services\SimplifiedReportSharing::class)->assess($p);
file_put_contents(__DIR__.'/hanns-period-trial-results.json',json_encode(['validation'=>$v,'assessment'=>$a],JSON_PRETTY_PRINT));
echo json_encode(['eligible'=>$a['eligible'],'blockers'=>$a['blockers'],'periods'=>collect($a['source_periods'])->map(fn($v)=>['document'=>$v['document'],'status'=>$v['status'],'ranges'=>$v['ranges']]),'warning_sections'=>collect($a['warnings'])->groupBy('section')->map->count(),'counts'=>$v['summary']['counts'],'invalid_issues'=>collect($v['records'])->where('status','INVALID')->pluck('issues')->flatten()->unique()->values()],JSON_PRETTY_PRINT);
