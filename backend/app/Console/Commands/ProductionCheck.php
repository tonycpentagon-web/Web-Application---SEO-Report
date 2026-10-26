<?php

namespace App\Console\Commands;

use App\Services\ProductionConfiguration;
use Illuminate\Console\Command;

class ProductionCheck extends Command
{
    protected $signature = 'production:check {--production : Evaluate production requirements even in a local environment}';

    protected $description = 'Check deployment configuration without printing secret values or claiming runtime readiness';

    public function handle(ProductionConfiguration $configuration): int
    {
        $errors = $configuration->errors($this->option('production'));
        foreach ($errors as $error) {
            $this->error($error);
        }
        if (! $errors) {
            $this->info('Configuration checks passed. Runtime/hosting verification is separate.');
        }

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
