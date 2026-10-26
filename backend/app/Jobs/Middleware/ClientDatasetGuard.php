<?php
namespace App\Jobs\Middleware;
class ClientDatasetGuard
{
    public function handle($job, $next): void
    {
        $lock=fopen(storage_path('framework/client-dataset-cleanup.lock'),'c+');
        if(!$lock||!flock($lock,LOCK_SH|LOCK_NB)){if($lock)fclose($lock);$job->release(10);return;}
        try{$next($job);}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
