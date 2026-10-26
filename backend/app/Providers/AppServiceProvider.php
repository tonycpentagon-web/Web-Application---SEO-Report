<?php

namespace App\Providers;

use App\Services\ProductionConfiguration;
use App\Services\WorkerHeartbeat;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkerHeartbeat::class);
    }

    public function boot(): void
    {
        $configurationErrors = app(ProductionConfiguration::class)->errors();
        if ($configurationErrors) {
            throw new \RuntimeException('Unsafe production configuration: '.implode(' ', $configurationErrors));
        }
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols());
        ResetPassword::createUrlUsing(fn (object $user, string $token) => config('insight.frontend_url').'/reset-password?token='.urlencode($token).'&email='.urlencode($user->email));
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(30)->by($r->ip()),
            Limit::perMinute(5)->by(mb_strtolower((string) $r->input('email')).'|'.$r->ip()),
        ]);
        RateLimiter::for('passwords', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('report-shares', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('operations', fn (Request $r) => Limit::perMinute(2)->by($r->user()?->id ?: $r->ip()));
        Queue::looping(fn ($event) => app(WorkerHeartbeat::class)->loop($event->queue));
        Queue::before(fn ($event) => app(WorkerHeartbeat::class)->state($event->job->getQueue(), 'busy'));
        Queue::after(fn ($event) => app(WorkerHeartbeat::class)->state($event->job->getQueue(), 'idle'));
        Queue::exceptionOccurred(fn ($event) => app(WorkerHeartbeat::class)->state($event->job->getQueue(), 'idle'));
        RateLimiter::for('exports', fn (Request $r) => Limit::perMinute(6)->by($r->user()?->id ?: $r->ip()));
    }
}
