<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

// Called only by docs/smoke.mjs against its temporary database.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! getenv('INSIGHT_SMOKE') || ! str_contains((string) getenv('DB_DATABASE'), 'insight-smoke-')) {
    fwrite(STDERR, "Smoke environment required.\n");
    exit(1);
}
if (($argv[1] ?? '') === 'disable') {
    // Leave the database session intact to test the active-account middleware itself.
    User::where('email', 'smoke@example.test')->update(['is_active' => false]);
    exit(0);
}
if (($argv[1] ?? '') === 'enable') {
    User::where('email', 'smoke@example.test')->update(['is_active' => true]);
    exit(0);
}
$user = new User;
$user->forceFill([
    'name' => 'Smoke Analyst', 'email' => 'smoke@example.test',
    'password' => getenv('SMOKE_PASSWORD'), 'is_super_admin' => true, 'role' => 'super_admin',
])->save();
