<?php

use App\Services\SystemHealth;
use Illuminate\Contracts\Console\Kernel;

require '/app/backend/vendor/autoload.php';
$app = require '/app/backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$health = app(SystemHealth::class)->snapshot();
$ok = match ($argv[1] ?? 'database') {
    'worker' => ($health['queues']['documents']['status'] ?? '') === 'healthy' && ($health['queues']['exports']['status'] ?? '') === 'healthy',
    'scheduler' => ($health['checks']['scheduler']['status'] ?? '') === 'healthy',
    default => ($health['checks']['database']['status'] ?? '') === 'healthy',
};
echo $ok ? "ready\n" : "not ready\n";
exit($ok ? 0 : 1);
