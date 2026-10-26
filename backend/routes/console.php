<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Account provisioning commands are auto-discovered in app/Console/Commands.
// Local synthetic staging can disable retention deletion without changing normal production policy.
if (! env('STAGE8_DISABLE_EXPORT_CLEANUP', false)) {
    Schedule::command('reports:cleanup-exports')->daily()->withoutOverlapping();
}
Schedule::call(fn () => Cache::put('operations:scheduler-last-seen', now()->toIso8601String(), now()->addMinutes(10)))->name('operations-scheduler-heartbeat')->everyMinute()->withoutOverlapping();

Schedule::command('workspace:clean-files')->everyMinute()->withoutOverlapping();
