<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Opt-in only; migrates a separately provisioned empty database and never wipes or drops it. */
class Mysql8IntegrationTest extends TestCase
{
    public function test_mysql8_strict_unicode_constraints_transactions_and_tenant_scoping(): void
    {
        $name = getenv('STAGE7_MYSQL_DATABASE');
        if (getenv('STAGE7_MYSQL_ALLOW') !== 'YES' || ! $name) {
            $this->markTestSkipped('MySQL 8 opt-in database is not configured.');
        }
        if (! preg_match('/^pentagon_test_[a-z0-9_]+$/D', $name)) {
            $this->fail('Only an explicitly named pentagon_test_* database is permitted.');
        }
        config(['database.connections.stage7_mysql' => ['driver' => 'mysql', 'host' => getenv('STAGE7_MYSQL_HOST') ?: '127.0.0.1', 'port' => getenv('STAGE7_MYSQL_PORT') ?: 3306, 'database' => $name, 'username' => getenv('STAGE7_MYSQL_USER'), 'password' => getenv('STAGE7_MYSQL_PASSWORD'), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true, 'engine' => 'InnoDB', 'prefix' => ''], 'database.default' => 'stage7_mysql']);
        $db = DB::connection('stage7_mysql');
        $version = $db->selectOne('SELECT VERSION() AS version')->version;
        $this->assertMatchesRegularExpression('/^8\./', $version, 'This gate requires actual MySQL 8, not MariaDB.');
        $this->assertSame(0, (int) $db->selectOne('SELECT count(*) AS total FROM information_schema.tables WHERE table_schema = ?', [$name])->total, 'Refusing migrations: test schema is not empty.');
        $this->artisan('migrate', ['--database' => 'stage7_mysql', '--force' => true])->assertSuccessful();
        $sqlMode = $db->selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        $this->assertStringContainsString('STRICT_TRANS_TABLES', $sqlMode);
        $owners = [];
        $projects = [];
        foreach (['صيدلية اختبار 🧪', 'Test SEO Company', 'Test Retail Business'] as $title) {
            $u = User::factory()->create(['role' => 'admin', 'is_super_admin' => false]);
            $c = new Client(['name' => $title, 'status' => 'active']);
            $c->forceFill(['user_id' => $u->id, 'identity_key' => hash('sha256', $title)])->save();
            $p = Project::factory()->create(['user_id' => $u->id, 'client_id' => $c->id]);
            $owners[] = $u;
            $projects[] = $p;
        }
        $this->assertSame('صيدلية اختبار 🧪', $projects[0]->client->name);
        $this->actingAs($owners[0])->getJson('/api/projects')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/projects/'.$projects[1]->id)->assertNotFound();
        $original = $projects[0]->name;
        try {
            $db->transaction(function () use ($projects) {
                $projects[0]->update(['name' => 'rollback fixture']);
                throw new \RuntimeException('Rollback fixture');
            });
        } catch (\RuntimeException) {
        }$this->assertSame($original, $projects[0]->fresh()->name);
        $id = (string) Str::ulid();
        $db->table('project_activity_logs')->insert(['id' => $id, 'project_id' => $projects[0]->id, 'actor_id' => $owners[0]->id, 'action' => 'mysql.fixture', 'properties' => json_encode(['unicode' => 'صيدلية', 'count' => 3])]);
        $this->assertSame(1, $db->table('project_activity_logs')->where('properties->count', 3)->count());
        // Database is retained for operator inspection; provisioning/removal is outside this test.
    }
}
