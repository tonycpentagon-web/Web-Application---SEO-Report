<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\NormalizationRun;
use App\Models\NormalizedRecord;
use App\Models\Project;
use App\Models\User;
use App\Services\ProductionConfiguration;
use App\Services\ReportDashboard;
use App\Services\ReportNormalization;
use App\Services\SystemHealth;
use App\Services\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Stage7ReadinessTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    private array $owners = [];

    private array $projects = [];

    private array $reports = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->root = User::factory()->create(['role' => 'super_admin', 'is_super_admin' => true]);
        foreach (['Synthetic Pharmacy', 'Test SEO Company', 'Test Retail Business'] as $i => $name) {
            $owner = User::factory()->create(['role' => 'admin', 'is_super_admin' => false]);
            $client = new Client(['name' => $name, 'status' => 'active']);
            $client->forceFill(['user_id' => $owner->id, 'identity_key' => hash('sha256', $name)])->save();
            $p = Project::factory()->create(['user_id' => $owner->id, 'client_id' => $client->id]);
            $r = $p->reports()->create(['title' => 'August synthetic report', 'reporting_month' => '2026-08', 'kind' => 'monthly', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'draft', 'created_by' => $owner->id, 'updated_by' => $owner->id]);
            $this->owners[] = $owner;
            $this->projects[] = $p;
            $this->reports[] = $r;
        }
    }

    protected function tearDown(): void
    {
        config(['database.default' => 'sqlite']);
        parent::tearDown();
    }

    private function base(int $i = 0): string
    {
        return '/api/projects/'.$this->projects[$i]->id.'/reports/'.$this->reports[$i]->id;
    }

    private function validProduction(): void
    {
        config(['app.env' => 'production', 'app.debug' => false, 'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.url' => 'https://reports.test', 'insight.frontend_url' => 'https://reports.test', 'session.secure' => true, 'session.encrypt' => true, 'session.http_only' => true, 'session.same_site' => 'lax', 'session.driver' => 'database', 'cache.default' => 'database', 'cors.allowed_origins' => ['https://reports.test'], 'cors.allowed_origins_patterns' => [], 'cors.supports_credentials' => true, 'sanctum.stateful' => ['reports.test'], 'database.default' => 'mysql', 'database.connections.mysql.url' => null, 'database.connections.mysql.database' => 'pentagon_fixture', 'database.connections.mysql.username' => 'fixture_user', 'database.connections.mysql.password' => 'fixture-only-value', 'database.connections.mysql.strict' => true, 'database.connections.mysql.charset' => 'utf8mb4', 'database.connections.mysql.engine' => 'InnoDB', 'queue.default' => 'database', 'queue.connections.database.retry_after' => 1200, 'mail.from.address' => 'reports@pentagon.test', 'mail.default' => 'smtp']);
    }

    public function test_production_validation_rejects_unsafe_defaults_and_accepts_explicit_configuration(): void
    {
        $validator = app(ProductionConfiguration::class);
        $this->assertNotEmpty($validator->errors(true));
        $this->validProduction();
        $this->assertSame([], $validator->errors(true));
        config(['app.debug' => true, 'session.secure' => false, 'queue.connections.database.retry_after' => 90, 'cors.allowed_origins' => ['*'], 'filesystems.disks.local.root' => public_path('uploads')]);
        $errors = implode(' ', $validator->errors(true));
        foreach (['APP_DEBUG', 'SESSION_SECURE_COOKIE', 'retry_after', 'CORS', 'Private storage'] as $term) {
            $this->assertStringContainsString($term, $errors);
        }
        $this->assertStringNotContainsString('fixture-only-value', $errors);
    }

    public function test_global_headers_and_production_exceptions_do_not_expose_secrets(): void
    {
        $this->getJson('/up')->assertOk()->assertExactJson(['status' => 'ok'])->assertHeader('X-Content-Type-Options', 'nosniff');
        Route::get('/api/stage7/error', fn () => throw new \RuntimeException('private-secret-sentinel /srv/private/database.sql'));
        config(['app.env' => 'production', 'app.debug' => false]);
        Log::spy();
        $response = $this->getJson('https://reports.test/api/stage7/error');
        $response->assertStatus(500)->assertExactJson(['message' => 'The server could not complete this request.'])->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->assertStringNotContainsString('private-secret-sentinel', $response->getContent());
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $message === 'Application operation failed.' && ! str_contains(json_encode($context), 'private-secret-sentinel'));
    }

    public function test_system_health_is_super_admin_only_and_not_a_public_diagnostic_dump(): void
    {
        $this->getJson('/api/system/health')->assertUnauthorized();
        foreach (['admin', 'seo_manager', 'seo_executive', 'client_viewer'] as $role) {
            $u = User::factory()->create(['role' => $role, 'is_super_admin' => false]);
            $this->actingAs($u)->getJson('/api/system/health')->assertForbidden();
            $this->postJson('/api/system/health/probe')->assertForbidden();
        }
        $response = $this->actingAs($this->root)->getJson('/api/system/health')->assertOk()->assertJsonPath('data.checks.database.status', 'healthy')->assertJsonPath('data.checks.storage.status', 'healthy')->assertJsonPath('data.queues.exports.status', 'not_verified')->assertJsonPath('data.checks.pdf.status', 'not_verified');
        foreach (['storage_path', 'DB_PASSWORD', 'APP_KEY', 'stack_trace'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_worker_health_requires_an_observed_heartbeat_and_detects_staleness(): void
    {
        $health = app(SystemHealth::class);
        $this->assertSame('not_verified', $health->snapshot()['queues']['exports']['status']);
        app(WorkerHeartbeat::class)->loop('documents,exports');
        $this->assertSame('healthy', $health->snapshot()['queues']['exports']['status']);
        DB::table('worker_heartbeats')->update(['last_seen_at' => now()->subMinutes(4)]);
        $this->assertSame('warning', $health->snapshot()['queues']['exports']['status']);
        app(WorkerHeartbeat::class)->state('exports', 'busy');
        $this->assertSame('healthy', $health->snapshot()['queues']['exports']['status']);
        $this->travel(7)->minutes();
        $this->assertSame('warning', $health->snapshot()['queues']['exports']['status']);
        $this->travelBack();
    }

    public function test_three_client_admins_cannot_read_or_mutate_foreign_resource_families(): void
    {
        foreach ($this->owners as $i => $owner) {
            $this->actingAs($owner)->getJson('/api/projects')->assertOk()->assertJsonPath('total', 1);
            foreach ($this->projects as $j => $project) {
                if ($i === $j) {
                    $this->getJson($this->base($j))->assertOk();

                    continue;
                }
                foreach (['', '/documents', '/data', '/data/records', '/data/warnings', '/data/preview', '/dashboard', '/summary', '/delivery', '/client-report', '/delivery/publications/foreign', '/delivery/exports/foreign/download'] as $suffix) {
                    $this->getJson($this->base($j).$suffix)->assertNotFound();
                }
                foreach (['/delivery/exports', '/delivery/publish', '/delivery/shares', '/data/normalize', '/data/records/bulk-review', '/summary'] as $suffix) {
                    $this->postJson($this->base($j).$suffix, [])->assertNotFound();
                }
                $this->putJson($this->base($j).'/shares', ['user_ids' => []])->assertNotFound();
                $this->getJson('/api/projects/'.$project->id.'/activity')->assertNotFound();
            }
        }
        $this->actingAs($this->root)->getJson('/api/projects')->assertOk()->assertJsonPath('total', 3);
    }

    public function test_private_uploads_pdf_and_histories_are_independent_between_clients(): void
    {
        $ids = [];
        foreach ($this->owners as $i => $owner) {
            $file = UploadedFile::fake()->createWithContent('synthetic-'.$i.'.csv', "category,count\nclient".$i.',10');
            $r = $this->actingAs($owner)->post($this->base($i).'/documents', ['files' => [$file]], ['Accept' => 'application/json'])->assertCreated();
            $ids[$i] = $r->json('data.0.id');
        }
        foreach ($this->owners as $i => $owner) {
            $this->actingAs($owner);
            foreach ($ids as $j => $id) {
                if ($i === $j) {
                    $this->getJson('/api/documents/'.$id)->assertOk();

                    continue;
                }foreach (['', '/download', '/preview', '/raw', '/extraction', '/assets', '/status', '/assets/foreign'] as $suffix) {
                    $this->getJson('/api/documents/'.$id.$suffix)->assertNotFound();
                }$this->postJson('/api/documents/'.$id.'/retry')->assertNotFound();
                $this->deleteJson('/api/documents/'.$id)->assertNotFound();
            }
            $e = $this->postJson($this->base($i).'/delivery/exports', ['type' => 'draft', 'profile' => 'client_preview'])->assertAccepted()->json('data');
            $foreign = ($i + 1) % 3;
            $this->actingAs($this->owners[$foreign])->getJson($this->base($i).'/delivery/exports/'.$e['id'].'/download')->assertNotFound();
        }
        foreach ($this->projects as $i => $p) {
            $this->assertSame(1, $p->activities()->where('action', 'document.uploaded')->count());
        }
        $this->assertDatabaseCount('report_documents', 3);
        $this->assertDatabaseCount('report_publications', 0);
    }

    public function test_existing_roles_memberships_multiple_periods_and_deactivation_are_enforced(): void
    {
        $p = $this->projects[1];
        $owner = $this->owners[1];
        $manager = User::factory()->create(['role' => 'seo_manager', 'is_super_admin' => false]);
        $executive = User::factory()->create(['role' => 'seo_executive', 'is_super_admin' => false]);
        $viewer = User::factory()->create(['role' => 'client_viewer', 'is_super_admin' => false]);
        $p->members()->attach($manager->id, ['assignment_role' => 'manager']);
        $p->members()->attach($executive->id, ['assignment_role' => 'member']);
        $this->projects[2]->members()->attach($manager->id, ['assignment_role' => 'manager']);
        $this->actingAs($manager)->getJson('/api/projects')->assertOk()->assertJsonPath('total', 2);
        $this->postJson($this->base(1).'/delivery/publish', ['confirmed' => true, 'version' => 1])->assertConflict();
        $this->actingAs($executive)->postJson($this->base(1).'/delivery/publish', ['confirmed' => true, 'version' => 1])->assertForbidden();
        $this->postJson($this->base(1).'/delivery/exports', ['type' => 'draft'])->assertAccepted();
        $this->actingAs($viewer)->getJson($this->base(1))->assertNotFound();
        $p->reports()->create(['title' => 'September', 'reporting_month' => '2026-09', 'kind' => 'monthly', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'draft', 'created_by' => $owner->id, 'updated_by' => $owner->id]);
        $this->actingAs($owner)->getJson('/api/projects/'.$p->id.'/reports')->assertOk()->assertJsonPath('total', 2);
        $owner->forceFill(['is_active' => false])->save();
        $this->getJson($this->base(1))->assertUnauthorized();
        $p->update(['status' => 'archived']);
        $this->actingAs($manager)->postJson($this->base(1).'/documents', [])->assertStatus(422);
        $this->postJson($this->base(1).'/delivery/publish', ['confirmed' => true, 'version' => 1])->assertConflict();
    }

    public function test_ntfs_streams_trailing_filename_characters_and_size_limits_are_rejected(): void
    {
        $this->actingAs($this->owners[0]);
        foreach (['source:stream.csv', 'source.csv.', 'source.csv '] as $name) {
            $this->post($this->base().'/documents', ['files' => [UploadedFile::fake()->createWithContent($name, "a,b\n1,2")]], ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $this->post($this->base().'/documents', ['files' => [UploadedFile::fake()->create('large.pdf', 26000, 'application/pdf')]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('report_documents', 0);
    }

    public function test_dashboard_verification_queries_are_bounded_as_record_count_grows(): void
    {
        $p = $this->reports[0];
        $snapshot = [];
        $engine = app(ReportNormalization::class);
        $run = NormalizationRun::create(['project_id' => $p->project_id, 'reporting_period_id' => $p->id, 'created_by' => $this->owners[0]->id, 'status' => 'completed', 'fingerprint' => $engine->fingerprint($p, $snapshot), 'input_snapshot' => $snapshot]);
        for ($i = 0; $i < 35; $i++) {
            NormalizedRecord::create(['project_id' => $p->project_id, 'reporting_period_id' => $p->id, 'normalization_run_id' => $run->id, 'kind' => 'event_metrics', 'section_key' => 'key_events', 'source_key' => hash('sha256', (string) $i), 'source_data' => ['event_name' => 'event-'.$i, 'count' => 1], 'raw_value' => [], 'source_hash' => hash('sha256', 'data'), 'confidence' => 'native']);
        }
        DB::enableQueryLog();
        app(ReportDashboard::class)->build($p, $this->owners[0]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $verificationQueries = array_filter($queries, fn ($q) => str_contains($q['query'], 'record_verifications'));
        $this->assertCount(1, $verificationQueries);
        $this->assertLessThan(30, count($queries));
    }

    public function test_admin_can_hold_two_explicit_company_memberships_without_access_to_the_third(): void
    {
        $admin = $this->owners[0];
        $this->projects[1]->client->admins()->attach($admin->id);
        $this->actingAs($admin)->getJson('/api/projects')->assertOk()->assertJsonPath('total', 2);
        $this->getJson($this->base(1))->assertOk();
        $this->getJson($this->base(2))->assertNotFound();
        $this->getJson('/api/clients/'.$this->projects[2]->client_id)->assertNotFound();
    }
}
