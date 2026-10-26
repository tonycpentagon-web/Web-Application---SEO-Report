<?php
require __DIR__.'/../../vendor/autoload.php';
[$script,$database,$report,$user,$preview,$start]=$argv;
if(!is_file($database)||!str_starts_with(basename($database),'lifecycle-race-'))exit(2);
$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>$database]);Illuminate\Support\Facades\DB::purge('sqlite');
while(microtime(true)<(float)$start)usleep(1000);
try{app(App\Services\WorkspaceLifecycle::class)->mutate(App\Models\User::findOrFail($user),'report',$report,['action'=>'trash','confirmed'=>true,'preview_token'=>$preview]);echo '200';}
catch(Symfony\Component\HttpKernel\Exception\HttpException $e){echo $e->getStatusCode();}
catch(Throwable $e){fwrite(STDERR,get_class($e));exit(1);}
