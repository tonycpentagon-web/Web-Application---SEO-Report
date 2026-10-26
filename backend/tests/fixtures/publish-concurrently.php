<?php

use App\Models\ReportingPeriod;
use App\Models\User;
use App\Services\ReportDelivery;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Disposable-database concurrency fixture. Never accepts a connection other than a temporary SQLite file.
require __DIR__.'/../../vendor/autoload.php';
[$script,$database,$report,$user,$storage,$start] = $argv;
if (! is_file($database) || ! str_starts_with(basename($database), 'delivery-race-')) {
    exit(2);
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database, 'filesystems.disks.local.root' => $storage]);
DB::purge('sqlite');
$p = ReportingPeriod::findOrFail($report);
$u = User::findOrFail($user);
while (microtime(true) < (float) $start) {
    usleep(1000);
}
try {
    $pub = app(ReportDelivery::class)->publish($p, $u, 1);
    echo '201';
} catch (HttpException $e) {
    echo $e->getStatusCode();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
