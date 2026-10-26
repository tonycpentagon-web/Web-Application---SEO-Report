<?php

namespace App\Console\Commands;

use App\Services\SystemHealth;
use Illuminate\Console\Command;

class SystemHealthCheck extends Command
{
    protected $signature = 'system:health {--probe : Execute actual Python/OCR and offline PDF probes}';

    protected $description = 'Display secret-free operator health checks; no publication or reporting mutations';

    public function handle(SystemHealth $health): int
    {
        if ($this->option('probe')) {
            $health->probe();
        }$data = $health->snapshot();
        $this->line(json_encode($data, JSON_PRETTY_PRINT));

        return collect($data['checks'])->contains('status', 'unavailable') ? self::FAILURE : self::SUCCESS;
    }
}
