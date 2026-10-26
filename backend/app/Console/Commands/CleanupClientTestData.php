<?php
namespace App\Console\Commands;
use App\Models\User;
use App\Services\ClientTestDataCleanup;
use Illuminate\Console\Command;
class CleanupClientTestData extends Command
{
    protected $signature='client-data:cleanup {--admin= : Active Super Admin ID} {--dry-run} {--prepare-backup} {--backup-root=} {--execute} {--backup=} {--confirm=}';
    protected $description='Verified local-only client test-data cleanup; dry-run by default, signed backup and explicit confirmation required.';
    public function handle(ClientTestDataCleanup $cleanup):int
    {
        try{
            $admin=User::findOrFail($this->option('admin'));$cleanup->local($admin);
            if($this->option('execute')){
                if($this->option('prepare-backup')||$this->option('dry-run'))throw new \RuntimeException('Execute cannot be combined with backup preparation or dry-run.');
                $folder=$this->option('backup');if(!$folder)throw new \RuntimeException('A verified backup set is required.');
                $m=$cleanup->manifest($folder);$phrase=$this->option('confirm');
                if(!$phrase&&$this->input->isInteractive())$phrase=$this->ask('Permanent deletion. Type exactly: '.$cleanup->phrase($m['plan']['fingerprint']));
                $result=$cleanup->execute($admin,$folder,(string)$phrase);$this->line(json_encode($result,JSON_PRETTY_PRINT));
                return $result['successful']?self::SUCCESS:self::FAILURE;
            }
            $plan=$cleanup->inventory($admin);$this->info('DRY RUN: no client data or files deleted.');
            $this->line(json_encode(['identity'=>$plan['identity'],'counts'=>$plan['counts'],'states'=>$plan['states'],'file_count'=>$plan['file_count'],'file_bytes'=>$plan['file_bytes'],'busy'=>$plan['busy'],'fingerprint'=>$plan['fingerprint']],JSON_PRETTY_PRINT));
            if($this->option('prepare-backup')){
                if(!$this->option('backup-root'))throw new \RuntimeException('Specify an absolute backup root outside the web workspace.');
                $backup=$cleanup->backup($admin,$this->option('backup-root'));$this->line(json_encode(['backup'=>$backup['folder'],'verification'=>$backup['verification'],'required_confirmation'=>$backup['confirmation']],JSON_PRETTY_PRINT));
            }
            return self::SUCCESS;
        }catch(\Throwable $e){$this->error($e->getMessage());return self::FAILURE;}
    }
}
