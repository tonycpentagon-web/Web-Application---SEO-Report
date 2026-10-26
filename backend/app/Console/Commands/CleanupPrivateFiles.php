<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB,Storage};
class CleanupPrivateFiles extends Command {
 protected $signature='workspace:clean-files {--dry-run}';
 protected $description='Remove only private files explicitly queued by an authorized permanent deletion';
 public function handle():int{
  $disk=Storage::disk('local');$root=realpath($disk->path(''));$removed=0;$blocked=0;
  foreach(DB::table('private_file_removals')->orderBy('created_at')->limit(100)->get() as $row){
   $path=str_replace('\\','/',$row->storage_path);
   if(!$root || !preg_match('~^(report-documents/[a-z0-9]{26}/|exports/[a-z0-9]{26}/|client-logos/)~i',$path)||preg_match('~(^|/)\.\.?(/|$)|:|\x00~',$path)){$blocked++;continue;}
   $absolute=$disk->path($path);$resolved=file_exists($absolute)?realpath($absolute):false;
   if($resolved && !str_starts_with(strtolower($resolved),strtolower($root.DIRECTORY_SEPARATOR))){$blocked++;continue;}
   if($this->option('dry-run')){$removed++;continue;}
   if(!$disk->exists($path)||$disk->delete($path)){DB::table('private_file_removals')->where('id',$row->id)->delete();$removed++;}
   else $blocked++;
  }
  $this->info($removed.' queued private files '.($this->option('dry-run')?'eligible.':'removed.').' '.$blocked.' blocked or failed; entries retained for retry.');return $blocked?self::FAILURE:self::SUCCESS;
 }
}
