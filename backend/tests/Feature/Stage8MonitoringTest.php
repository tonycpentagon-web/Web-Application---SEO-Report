<?php

namespace Tests\Feature;

use App\Services\ProcessEnvironment;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Stage8MonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
    }

    public function test_scheduler_requires_observed_and_recent_execution(): void
    {
        $service = app(SystemHealth::class);
        $this->assertSame('not_verified', $service->snapshot()['checks']['scheduler']['status']);
        Cache::put('operations:scheduler-last-seen', now()->subMinutes(5)->toIso8601String(), 600);
        $this->assertSame('warning', $service->snapshot()['checks']['scheduler']['status']);
        Cache::put('operations:scheduler-last-seen', now()->toIso8601String(), 600);
        $this->assertSame('healthy', $service->snapshot()['checks']['scheduler']['status']);
    }

    public function test_real_failed_runtime_probes_do_not_report_healthy_or_leak_paths(): void
    {
        config(['documents.python' => 'nonexistent-stage8-python', 'delivery.node' => 'nonexistent-stage8-node']);
        $result = app(SystemHealth::class)->probe();
        $this->assertSame('unavailable', $result['python']['status']);
        $this->assertSame('unavailable', $result['pdf']['status']);
        $this->assertStringNotContainsString('nonexistent-stage8', json_encode($result));
        $this->assertSame('unavailable', app(SystemHealth::class)->snapshot()['checks']['pdf']['status']);
    }

    public function test_unconfigured_runtime_is_distinct_from_unverified_and_expired_probes(): void
    {
        config(['documents.python' => '', 'delivery.node' => '']);
        $service = app(SystemHealth::class);
        $this->assertSame('not_configured', $service->snapshot()['checks']['pdf']['status']);
        config(['documents.python' => 'python', 'delivery.node' => 'node']);
        $this->assertSame('not_verified', $service->snapshot()['checks']['pdf']['status']);
        Cache::put('operations:runtime-probes', ['checked_at' => now()->toIso8601String(), 'pdf' => ['status' => 'healthy', 'message' => 'Observed PDF']], now()->addMinutes(15));
        $this->assertSame('healthy', $service->snapshot()['checks']['pdf']['status']);
        $this->travel(16)->minutes();
        $this->assertSame('not_verified', $service->snapshot()['checks']['pdf']['status']);
        $this->travelBack();
    }

    public function test_child_process_environment_has_real_temporary_directory_without_request_secrets(): void
    {
        $env = ProcessEnvironment::local();
        $this->assertSame(sys_get_temp_dir(), $env['TMP']);
        $this->assertTrue(is_dir($env['TEMP']));
        foreach (['APP_KEY', 'DB_PASSWORD', 'HTTP_AUTHORIZATION', 'HTTP_COOKIE'] as $secret) {
            $this->assertArrayNotHasKey($secret, $env);
        }
        $process = new Process([PHP_BINARY, '-r', 'echo is_dir(getenv("TMPDIR")) ? "ready" : "missing";'], base_path(), $env);
        $process->mustRun();
        $this->assertSame('ready', $process->getOutput());
    }
}
