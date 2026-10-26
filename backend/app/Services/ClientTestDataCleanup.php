<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\{Artisan, DB, Storage};
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/** Explicit local test-data disposal. No schema, account, setting or global-log deletion. */
class ClientTestDataCleanup
{
    public const TABLES = [
        'report_share_links','report_shares','report_pdf_exports','report_delivery_events',
        'report_manager_approvals','report_summary_approvals','record_validation_results',
        'record_verifications','record_corrections','bulk_review_requests','validation_warnings',
        'metric_source_references','report_source_scope_verifications','report_generations',
        'normalized_records','report_sections','report_summary_revisions','report_validation_runs',
        'normalization_runs','document_assets','document_extracted_content','document_extraction_runs',
        'report_documents','project_activity_logs','report_publications','reporting_periods',
        'project_memberships','projects','client_memberships','clients',
    ];
    private const JOBS = ['App\\Jobs\\ProcessReportDocument','App\\Jobs\\NormalizeReport','App\\Jobs\\GenerateMonthlyReport','App\\Jobs\\GenerateReportPdf'];

    public function local(User $admin): array
    {
        $test = app()->runningUnitTests() && app()->environment('testing');
        if (!$admin->is_active || !$admin->is_super_admin) throw new RuntimeException('An active Super Admin is required for global client test-data cleanup.');
        if (!app()->environment('local') && !$test) throw new RuntimeException('Cleanup is restricted to the verified local development environment.');
        $db = DB::connection()->getDatabaseName();
        if (DB::connection()->getDriverName() !== 'sqlite') throw new RuntimeException('This installation is SQLite. Other database engines are refused; no remote database is supported.');
        if (!$test) {
            $env = file_get_contents(base_path('.env'));
            if (!preg_match('/^APP_ENV\s*=\s*["\x27]?local["\x27]?\s*$/m', $env)) throw new RuntimeException('The on-disk environment must independently declare APP_ENV=local.');
            if (!in_array(parse_url(config('app.url'), PHP_URL_HOST), ['127.0.0.1','localhost','::1'], true)) throw new RuntimeException('APP_URL must be a loopback address.');
            if ($this->canonical($db) !== $this->canonical(database_path('database.sqlite'))) throw new RuntimeException('Database is not the expected local database for this checkout.');
            if ($this->canonical(Storage::disk('local')->path('')) !== $this->canonical(storage_path('app/private'))) throw new RuntimeException('Unexpected private storage root.');
        }
        return ['environment'=>app()->environment(),'driver'=>'sqlite','database'=>$db,'application'=>base_path(),'url'=>config('app.url'),'storage'=>Storage::disk('local')->path('')];
    }

    public function lock()
    {
        $lock=fopen(storage_path('framework/client-dataset-cleanup.lock'),'c+');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('A report worker or another cleanup holds the dataset lock. Wait for processing to finish.');
        return $lock;
    }

    private function canonical(string $path): string { return strtolower(rtrim(str_replace('\\','/',realpath($path) ?: $path),'/')); }

    public function safePath(string $relative, bool $mustExist=false): string
    {
        $relative=str_replace('\\','/',$relative);
        // Historical local-disk records used a single leading slash for disk-relative keys.
        if(str_starts_with($relative,'/') && !str_starts_with($relative,'//'))$relative=substr($relative,1);
        if (!preg_match('~^(report-documents/[a-z0-9]{26}/|exports/[a-z0-9]{26}/|client-logos/)~i',$relative) || preg_match('~(^|/)\.{1,2}(/|$)|:|\x00~',$relative)) throw new RuntimeException('Unsafe managed path rejected: '.$relative);
        $root=rtrim(Storage::disk('local')->path(''),'/\\');$absolute=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
        if ($mustExist && !is_file($absolute)) throw new RuntimeException('Referenced managed file is missing: '.$relative);
        if (file_exists($absolute) && $this->canonical($absolute)!==strtolower(str_replace('\\','/',$absolute))) throw new RuntimeException('Managed path redirects through a link/junction: '.$relative);
        $walk=dirname($absolute);
        while(strlen($walk)>strlen($root)) { if(is_link($walk)) throw new RuntimeException('Linked managed directory refused.');$parent=dirname($walk);if($walk===$parent)break;$walk=$parent; }
        if(is_link($absolute))throw new RuntimeException('Linked managed file refused.');
        return $absolute;
    }

    private function query(string $table)
    {
        $q=DB::table($table);
        if($table==='retained_lifecycle_events')$q->whereIn('entity_type',['client','project','report','document']);
        if(in_array($table,['jobs','failed_jobs'])){
            $ids=[];foreach(DB::table($table)->get(['id','payload']) as $row){$p=json_decode($row->payload,true);if(in_array($p['data']['commandName']??'',self::JOBS,true))$ids[]=$row->id;}
            $q->whereIn('id',$ids);
        }
        if($table==='private_file_removals')$q->where(fn($q)=>$q->where('storage_path','like','report-documents/%')->orWhere('storage_path','like','exports/%')->orWhere('storage_path','like','client-logos/%'));
        return $q;
    }

    private function digest(string $table): string
    {
        $h=hash_init('sha256');foreach($this->query($table)->orderBy('id')->cursor() as $row)hash_update($h,json_encode((array)$row,JSON_THROW_ON_ERROR)."\n");return hash_final($h);
    }

    public function inventory(User $admin): array
    {
        $identity=$this->local($admin);$tables=[];$hashes=[];
        foreach([...self::TABLES,'retained_lifecycle_events','jobs','failed_jobs','private_file_removals'] as $t){$tables[$t]=$this->query($t)->count();$hashes[$t]=$this->digest($t);}
        $files=[];$root=rtrim(Storage::disk('local')->path(''),'/\\');
        foreach(['report-documents','exports','client-logos'] as $prefix){
            $folder=$root.DIRECTORY_SEPARATOR.$prefix;if(!is_dir($folder))continue;
            if(is_link($folder))throw new RuntimeException('Managed root is linked.');
            $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder,\FilesystemIterator::SKIP_DOTS));
            foreach($it as $file){if($file->isDir())continue;$relative=str_replace('\\','/',substr($file->getPathname(),strlen(rtrim($root,'/\\'))+1));$path=$this->safePath($relative,true);$files[$relative]=['bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path)];}
        }
        ksort($files);
        foreach(['report_documents'=>'storage_path','document_assets'=>'storage_path','document_extraction_runs'=>'raw_path','report_pdf_exports'=>'storage_path','clients'=>'logo_path','private_file_removals'=>'storage_path'] as $t=>$column){
            foreach($this->query($t)->whereNotNull($column)->pluck($column) as $path){$this->safePath($path,$t!=='private_file_removals');}
        }
        $busy=[];foreach(['document_extraction_runs','normalization_runs','report_generations','report_pdf_exports'] as $t){$n=DB::table($t)->whereIn('status',['processing','rendering'])->count();if($n)$busy[$t]=$n;}
        if(DB::table('jobs')->whereNotNull('reserved_at')->exists())$busy['reserved_jobs']=DB::table('jobs')->whereNotNull('reserved_at')->count();
        $states=[];foreach(['clients','projects','reporting_periods'] as $t){$states[$t]=['total'=>$tables[$t],'trashed'=>DB::table($t)->whereNotNull('deleted_at')->count(),'archived'=>DB::table($t)->where('status','archived')->count()];}
        return ['identity'=>$identity,'counts'=>$tables,'states'=>$states,'files'=>$files,'file_count'=>count($files),'file_bytes'=>array_sum(array_column($files,'bytes')),'busy'=>$busy,'fingerprint'=>hash('sha256',json_encode([$identity,$hashes,$files],JSON_THROW_ON_ERROR))];
    }

    private function write(string $path,array $data): void
    {
        if(file_put_contents($path.'.tmp',json_encode($data,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)===false || !rename($path.'.tmp',$path))throw new RuntimeException('Unable to atomically write cleanup journal/manifest.');
    }

    public function backup(User $admin,string $root): array
    {
        $this->local($admin);
        if(!preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~',$root)||preg_match('~(^|[/\\\\])\.\.([/\\\\]|$)|\x00~',$root))throw new RuntimeException('Use an absolute backup directory without traversal.');
        if(!is_dir($root)&&!mkdir($root,0700,true))throw new RuntimeException('Backup root could not be created.');
        $backupRoot=$this->canonical($root);$project=$this->canonical(base_path('..'));
        $webRoot=$this->canonical(dirname(base_path('..')));
        if($backupRoot===$project||str_starts_with($backupRoot,$project.'/') || (basename($webRoot)==='htdocs' && ($backupRoot===$webRoot || str_starts_with($backupRoot,$webRoot.'/'))))throw new RuntimeException('Backups must be outside the application/web workspace.');
        $lock=$this->lock();$wasDown=app()->isDownForMaintenance();
        try{
            if(!$wasDown)Artisan::call('down');
            $plan=$this->inventory($admin);if($plan['busy'])throw new RuntimeException('Processing is active; backup refused until workers are idle.');
            $folder=rtrim($root,'/\\').DIRECTORY_SEPARATOR.now()->format('Ymd-His').'-'.Str::lower((string)Str::ulid());
            if(!mkdir($folder,0700,true))throw new RuntimeException('Cannot create backup set.');
            $database=$folder.DIRECTORY_SEPARATOR.'database.sqlite';
            DB::connection()->getPdo()->exec('VACUUM INTO '.DB::connection()->getPdo()->quote(str_replace('\\','/',$database)));
            $zip=new ZipArchive;if($zip->open($folder.'/client-files.zip',ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Cannot create private-files archive.');
            foreach($plan['files'] as $path=>$meta)if(!$zip->addFile($this->safePath($path,true),'managed/'.$path))throw new RuntimeException('Cannot archive managed file.');
            if(!$zip->close())throw new RuntimeException('Archive finalization failed.');
            // libzip removes a new empty archive; retain a valid empty ZIP for repeat cleanups.
            if(!$plan['files'] && !is_file($folder.'/client-files.zip'))file_put_contents($folder.'/client-files.zip',hex2bin('504b0506000000000000000000000000000000000000')); 
            if($this->inventory($admin)['fingerprint']!==$plan['fingerprint'])throw new RuntimeException('Dataset changed during backup; this set is not approved for cleanup.');
            $manifest=['schema'=>1,'created_at'=>now()->toIso8601String(),'admin_id'=>$admin->id,'plan'=>$plan,'database_sha256'=>hash_file('sha256',$database),'archive_sha256'=>hash_file('sha256',$folder.'/client-files.zip'),'key_fingerprint'=>hash('sha256',config('app.key'))];
            $manifest['signature']=hash_hmac('sha256',json_encode($manifest,JSON_THROW_ON_ERROR),config('app.key'));
            $this->write($folder.'/manifest.json',$manifest);
            $verification=$this->verify($folder);$this->write($folder.'/verification.json',$verification);
            return ['folder'=>$folder,'manifest'=>$manifest,'verification'=>$verification,'confirmation'=>$this->phrase($plan['fingerprint'])];
        }finally{if(!$wasDown)Artisan::call('up');flock($lock,LOCK_UN);fclose($lock);}
    }

    public function phrase(string $fingerprint): string { return 'DELETE ALL CLIENT TEST DATA '.substr($fingerprint,0,12); }

    public function manifest(string $folder): array
    {
        $m=json_decode(file_get_contents($folder.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);$signature=$m['signature']??'';unset($m['signature']);
        if(!hash_equals(hash_hmac('sha256',json_encode($m,JSON_THROW_ON_ERROR),config('app.key')),$signature))throw new RuntimeException('Backup manifest signature is invalid.');
        $m['signature']=$signature;return $m;
    }

    public function verify(string $folder): array
    {
        $m=$this->manifest($folder);$database=$folder.'/database.sqlite';$archive=$folder.'/client-files.zip';
        if(!hash_equals($m['database_sha256'],hash_file('sha256',$database))||!hash_equals($m['archive_sha256'],hash_file('sha256',$archive)))throw new RuntimeException('Backup database or archive checksum mismatch.');
        $restore=$folder.'/restore-check';if(!is_dir($restore)&&!mkdir($restore,0700,true))throw new RuntimeException('Cannot create restore verification directory.');
        if(!copy($database,$restore.'/database.sqlite'))throw new RuntimeException('Database restore copy failed.');
        $db=new \PDO('sqlite:'.$restore.'/database.sqlite');$db->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
        if($db->query('PRAGMA integrity_check')->fetchColumn()!=='ok'||$db->query('PRAGMA foreign_key_check')->fetchAll())throw new RuntimeException('Restored database integrity or foreign-key validation failed.');
        $tables=$db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);$counts=[];
        foreach($tables as $t){if(!preg_match('/^[a-z_]+$/i',$t))throw new RuntimeException('Unexpected table identifier.');$counts[$t]=(int)$db->query('SELECT COUNT(*) FROM "'.$t.'"')->fetchColumn();}
        foreach(self::TABLES as $t)if($counts[$t]!==$m['plan']['counts'][$t])throw new RuntimeException('Restored selected-table count differs: '.$t);
        $zip=new ZipArchive;if($zip->open($archive)!==true)throw new RuntimeException('Unreadable archive.');
        $verified=0;foreach($m['plan']['files'] as $path=>$meta){
            $this->safePath($path);$entry='managed/'.$path;$stream=$zip->getStream($entry);if(!$stream)throw new RuntimeException('Archive entry missing.');
            $target=$restore.'/files/'.$path;if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);
            $output=fopen($target,'wb');if(!$output||stream_copy_to_stream($stream,$output)!==$meta['bytes'])throw new RuntimeException('Restore file copy failed.');fclose($stream);fclose($output);
            if(hash_file('sha256',$target)!==$meta['sha256'])throw new RuntimeException('Restored file checksum mismatch.');$verified++;
        }
        if($zip->numFiles!==count($m['plan']['files']))throw new RuntimeException('Unexpected extra archive entries.');$zip->close();
        return ['verified'=>true,'restored_database_integrity'=>'ok','foreign_key_violations'=>0,'restored_table_counts'=>$counts,'verified_files'=>$verified,'restored_directory'=>$restore,'verified_at'=>now()->toIso8601String()];
    }

    public function execute(User $admin,string $folder,string $confirmation): array
    {
        $this->local($admin);$m=$this->manifest($folder);
        if(!hash_equals($this->phrase($m['plan']['fingerprint']),$confirmation))throw new RuntimeException('Exact dataset-bound administrator confirmation is required.');
        if($m['plan']['identity']!==$this->local($admin))throw new RuntimeException('Backup belongs to a different database/environment.');
        $this->verify($folder);$lock=$this->lock();$wasDown=app()->isDownForMaintenance();
        try{
            if(!$wasDown)Artisan::call('down');
            $journal=is_file($folder.'/execution.json')?json_decode(file_get_contents($folder.'/execution.json'),true,512,JSON_THROW_ON_ERROR):null;
            if(!($journal['database_committed']??false)){
                $plan=$this->inventory($admin);if($plan['busy']||!hash_equals($m['plan']['fingerprint'],$plan['fingerprint']))throw new RuntimeException('Data/files changed or processing started after backup. Prepare a new backup and obtain fresh confirmation.');
                $preserved=[];foreach(['users','migrations','password_reset_tokens','personal_access_tokens'] as $t)$preserved[$t]=DB::table($t)->orderBy($t==='password_reset_tokens'?'email':'id')->get()->toJson();
                $this->write($folder.'/execution.json',['database_committed'=>false,'state'=>'prepared','admin_id'=>$admin->id,'fingerprint'=>$plan['fingerprint'],'note'=>'If interrupted during commit, stop and inspect database before resuming.']);
                $deleted=[];DB::transaction(function() use(&$deleted,$preserved){
                    DB::statement('PRAGMA defer_foreign_keys = ON');
                    DB::table('report_documents')->update(['current_run_id'=>null]);
                    // Child-to-parent ordering, with deferred checks for the document/run cycle.
                    foreach(self::TABLES as $t)$deleted[$t]=$this->query($t)->delete();
                    foreach(['retained_lifecycle_events','jobs','failed_jobs','private_file_removals'] as $t)$deleted[$t]=$this->query($t)->delete();
                    if(DB::select('PRAGMA foreign_key_check'))throw new RuntimeException('Foreign-key check failed; database deletion rolled back.');
                    foreach($preserved as $t=>$json)if(DB::table($t)->orderBy($t==='password_reset_tokens'?'email':'id')->get()->toJson()!==$json)throw new RuntimeException('Protected table changed; cleanup rolled back.');
                });
                $journal=['database_committed'=>true,'admin_id'=>$admin->id,'deleted_records'=>$deleted,'deleted_files'=>[],'pending_files'=>array_keys($m['plan']['files']),'failures'=>[],'started_at'=>now()->toIso8601String()];
                $this->write($folder.'/execution.json',$journal);
            }
            $journal['failures']=[];
            foreach($journal['pending_files'] as $path){
                try{
                    $absolute=$this->safePath($path);$meta=$m['plan']['files'][$path];
                    foreach(['report_documents'=>'storage_path','document_assets'=>'storage_path','document_extraction_runs'=>'raw_path','report_pdf_exports'=>'storage_path','clients'=>'logo_path'] as $t=>$column)if(DB::table($t)->whereIn($column,[$path,'/'.$path])->exists())throw new RuntimeException('Path is referenced by new data; retained.');
                    if(is_file($absolute)&&!hash_equals($meta['sha256'],hash_file('sha256',$absolute)))throw new RuntimeException('File changed since backup; retained.');
                    if(is_file($absolute)&&!unlink($absolute))throw new RuntimeException('File removal failed.');
                    $journal['deleted_files'][]=$path;$journal['pending_files']=array_values(array_diff($journal['pending_files'],[$path]));
                }catch(\Throwable $e){$journal['failures'][$path]=$e->getMessage();}
                $this->write($folder.'/execution.json',$journal);
            }
            $journal['completed_at']=now()->toIso8601String();$journal['successful']=!$journal['pending_files'];$this->write($folder.'/execution.json',$journal);
            return $journal;
        }finally{if(!$wasDown)Artisan::call('up');flock($lock,LOCK_UN);fclose($lock);}
    }
}
