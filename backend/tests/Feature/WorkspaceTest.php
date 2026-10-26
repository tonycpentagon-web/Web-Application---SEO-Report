<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\User;
use App\Services\LegacyClientBackfill;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $role = 'super_admin', array $extra = []): User
    {
        return User::factory()->create([...['role' => $role, 'is_super_admin' => $role === 'super_admin'], ...$extra]);
    }

    private function client(User $owner, string $name = 'Client Alpha'): Client
    {
        $c = new Client(['name' => $name, 'website' => 'https://example.test', 'status' => 'active']);
        $c->forceFill(['user_id' => $owner->id, 'identity_key' => hash('sha256', mb_strtolower($name))])->save();

        return $c;
    }

    private function project(User $owner, ?Client $client = null): Project
    {
        $client ??= $this->client($owner);

        return Project::factory()->create(['user_id' => $owner->id, 'client_id' => $client->id]);
    }

    private function reportPayload(array $extra = []): array
    {
        return [...['title' => 'August SEO report', 'reporting_month' => '2026-08', 'kind' => 'monthly'], ...$extra];
    }

    private function report(User $u, Project $p, array $extra = []): ReportingPeriod
    {
        return $p->reports()->create([...['title' => 'August SEO report', 'reporting_month' => '2026-08', 'kind' => 'monthly', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'draft', 'created_by' => $u->id, 'updated_by' => $u->id], ...$extra]);
    }

    public function test_client_create_edit_duplicate_validation_archive_and_restore(): void
    {
        $admin = $this->person();
        $this->actingAs($admin);
        $r = $this->postJson('/api/clients', ['name' => "Hann's Pharmacy", 'website' => 'https://hannspharmacy.com/', 'brand_color' => '#087e98'])->assertCreated();
        $id = $r->json('data.id');
        $this->postJson('/api/clients', ['name' => "hann's pharmacy"])->assertUnprocessable();
        $this->postJson('/api/clients', ['name' => '', 'website' => 'javascript:alert(1)', 'brand_color' => 'red'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'website', 'brand_color']);
        $this->putJson('/api/clients/'.$id, ['name' => "Hann's Pharmacy", 'country' => 'United States', 'status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->getJson('/api/clients?status=archived&search=Hann')->assertOk()->assertJsonPath('total', 1);
        $this->putJson('/api/clients/'.$id, ['name' => "Hann's Pharmacy", 'status' => 'active'])->assertOk();
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_logo_upload_is_private_validated_and_restricted(): void
    {
        Storage::fake('local');
        $admin = $this->person();
        $c = $this->client($admin);
        $image = UploadedFile::fake()->createWithContent('logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aO9sAAAAASUVORK5CYII='));
        $this->actingAs($admin)->post('/api/clients/'.$c->id.'/logo', ['logo' => $image], ['Accept' => 'application/json'])->assertOk()->assertJsonMissingPath('data.logo_path');
        Storage::disk('local')->assertExists($c->fresh()->logo_path);
        $this->get('/api/clients/'.$c->id.'/logo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->postJson('/api/clients/'.$c->id.'/logo', ['logo' => UploadedFile::fake()->createWithContent('danger.svg', '<svg><script>alert(1)</script></svg>')])->assertUnprocessable();
        $this->actingAs($this->person('seo_executive'))->get('/api/clients/'.$c->id.'/logo', ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_admins_are_scoped_to_assigned_clients_and_cannot_assign_themselves(): void
    {
        $super = $this->person();
        $a = $this->person('admin');
        $b = $this->person('admin');
        $c = $this->client($super);
        $p = $this->project($super, $c);
        $this->actingAs($a)->getJson('/api/clients/'.$c->id)->assertNotFound();
        $this->putJson('/api/clients/'.$c->id.'/admins', ['user_ids' => [$a->id]])->assertForbidden();
        $this->actingAs($super)->putJson('/api/clients/'.$c->id.'/admins', ['user_ids' => [$a->id]])->assertOk();
        $this->actingAs($a)->getJson('/api/projects/'.$p->id)->assertOk();
        $this->putJson('/api/clients/'.$c->id, ['name' => 'Assigned organization client'])->assertOk();
        $this->actingAs($b)->getJson('/api/projects/'.$p->id)->assertNotFound();
        $this->getJson('/api/clients')->assertJsonPath('total', 0);
    }

    public function test_project_fields_filters_sort_archival_and_relationship_integrity(): void
    {
        $admin = $this->person();
        $c = $this->client($admin);
        $v = ['name' => 'Website SEO', 'client_id' => $c->id, 'website' => 'https://example.test', 'reporting_frequency' => 'monthly', 'start_date' => '2026-08-01'];
        $p = $this->actingAs($admin)->postJson('/api/projects', $v)->assertCreated()->json('data');
        $this->putJson('/api/projects/'.$p['id'], [...$v, 'name' => 'Local SEO', 'status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->getJson('/api/projects?client_id='.$c->id.'&status=archived&sort=name&direction=asc')->assertJsonPath('total', 1);
        $this->putJson('/api/projects/'.$p['id'], [...$v, 'status' => 'active'])->assertOk();
        $this->getJson('/api/projects?sort=sql-injection')->assertUnprocessable();
        $this->postJson('/api/projects', [...$v, 'reporting_frequency' => 'weekly'])->assertUnprocessable();
        $this->assertDatabaseHas('projects', ['id' => $p['id'], 'user_id' => $admin->id, 'client_id' => $c->id]);
    }

    public function test_manager_and_executive_memberships_restrict_operations_and_foreign_ids(): void
    {
        $super = $this->person();
        $p = $this->project($super);
        $other = $this->project($super, $this->client($super, 'Other'));
        $manager = $this->person('seo_manager', ['managed_by' => $super->id]);
        $exec = $this->person('seo_executive', ['managed_by' => $super->id]);
        $this->actingAs($super)->putJson('/api/projects/'.$p->id.'/team', ['manager_id' => $manager->id, 'member_ids' => [$exec->id]])->assertOk();
        $this->actingAs($manager)->getJson('/api/projects/'.$p->id)->assertOk()->assertJsonPath('data.permissions.manage', true);
        $this->getJson('/api/projects/'.$other->id)->assertNotFound();
        $this->actingAs($exec)->getJson('/api/projects/'.$p->id)->assertOk()->assertJsonPath('data.permissions.manage', false)->assertJsonPath('data.permissions.work_reports', true);
        $this->putJson('/api/projects/'.$p->id, ['name' => 'Hack', 'client_id' => $p->client_id, 'website' => 'https://example.test'])->assertForbidden();
        $this->putJson('/api/projects/'.$p->id.'/team', ['manager_id' => null, 'member_ids' => []])->assertForbidden();
        $this->postJson('/api/clients', ['name' => 'Not allowed'])->assertForbidden();
        $this->actingAs($super)->putJson('/api/projects/'.$p->id.'/team', ['manager_id' => null, 'member_ids' => []])->assertOk();
        $this->actingAs($exec)->getJson('/api/projects/'.$p->id)->assertNotFound();
    }

    public function test_team_assignment_rejects_out_of_organization_users_and_wrong_roles(): void
    {
        $a = $this->person('admin');
        $p = $this->project($a);
        $foreign = $this->person('seo_executive');
        $own = $this->person('seo_executive', ['managed_by' => $a->id]);
        $this->actingAs($a)->putJson('/api/projects/'.$p->id.'/team', ['manager_id' => null, 'member_ids' => [$foreign->id]])->assertUnprocessable();
        $this->putJson('/api/projects/'.$p->id.'/team', ['manager_id' => $own->id, 'member_ids' => []])->assertUnprocessable();
        $this->putJson('/api/projects/'.$p->id.'/team', ['manager_id' => null, 'member_ids' => [$own->id, $own->id]])->assertUnprocessable();
    }

    public function test_monthly_and_custom_report_ranges_duplicates_and_invalid_dates(): void
    {
        $u = $this->person();
        $p = $this->project($u);
        $url = '/api/projects/'.$p->id.'/reports';
        $this->actingAs($u);
        $r = $this->postJson($url, $this->reportPayload())->assertCreated()->assertJsonPath('data.start_date', '2026-08-01')->assertJsonPath('data.end_date', '2026-08-31')->assertJsonPath('data.status', 'draft')->assertJsonPath('data.has_data', false);
        $this->postJson($url, $this->reportPayload())->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->postJson($url, $this->reportPayload(['kind' => 'custom', 'start_date' => '2026-08-10', 'end_date' => '2026-08-20']))->assertCreated();
        $this->postJson($url, $this->reportPayload(['kind' => 'custom', 'start_date' => '2026-08-20', 'end_date' => '2026-08-10']))->assertUnprocessable();
        $this->postJson($url, $this->reportPayload(['reporting_month' => '2026-13']))->assertUnprocessable();
        $this->getJson($url)->assertJsonPath('total', 2);
        $this->assertDatabaseCount('reporting_periods', 2);
    }

    public function test_report_edits_preserve_history_and_reject_stale_writes(): void
    {
        $u = $this->person();
        $p = $this->project($u);
        $r = $this->report($u, $p);
        $url = '/api/projects/'.$p->id.'/reports/'.$r->id;
        $this->actingAs($u);
        $this->putJson($url, $this->reportPayload(['title' => 'Revised August', 'version' => 1]))->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($url, $this->reportPayload(['title' => 'Stale overwrite', 'version' => 1]))->assertStatus(409);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.history')->assertJsonPath('data.history.0.properties.before.title', 'August SEO report');
        $this->assertSame('Revised August', $r->fresh()->title);
    }

    public function test_report_transition_permissions_and_archival_history(): void
    {
        $u = $this->person();
        $p = $this->project($u);
        $exec = $this->person('seo_executive', ['managed_by' => $u->id]);
        $p->members()->attach($exec, ['assignment_role' => 'member']);
        $r = $this->report($u, $p);
        $url = '/api/projects/'.$p->id.'/reports/'.$r->id;
        $this->actingAs($exec)->patchJson($url.'/status', ['status' => 'approved', 'version' => 1])->assertStatus(409);
        $this->patchJson($url.'/status', ['status' => 'needs_review', 'version' => 1])->assertOk();
        $this->putJson($url, $this->reportPayload(['version' => 2]))->assertStatus(409);
        $this->actingAs($u)->patchJson($url.'/status', ['status' => 'published', 'version' => 2])->assertStatus(409);
        foreach ([['approved', 2], ['archived', 3], ['draft', 4]] as [$status,$version]) {
            $this->patchJson($url.'/status', compact('status', 'version'))->assertOk();
        }
        $this->assertSame('draft', $r->fresh()->status);
        $this->assertDatabaseCount('project_activity_logs', 4);
    }

    public function test_explicit_viewer_sharing_does_not_expose_other_periods_or_internal_fields(): void
    {
        $u = $this->person();
        $p = $this->project($u);
        $viewer = $this->person('client_viewer', ['managed_by' => $u->id]);
        $r = $this->report($u, $p, ['status' => 'published']);
        $private = $this->report($u, $p, ['reporting_month' => '2026-09', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        $this->actingAs($viewer)->getJson('/api/projects/'.$p->id)->assertNotFound();
        $this->actingAs($u)->putJson('/api/projects/'.$p->id.'/reports/'.$r->id.'/shares', ['user_ids' => [$viewer->id]])->assertConflict();
        // Legacy grant fixture: existing sharing is retained, while new sharing of an unvalidated report is blocked.
        $r->viewers()->attach($viewer->id, ['shared_by' => $u->id, 'expires_at' => now()->addDay()]);
        $this->actingAs($viewer)->getJson('/api/projects/'.$p->id)->assertOk()->assertJsonPath('data.reporting_periods_count', 1)->assertJsonPath('data.members', [])->assertJsonMissingPath('data.client.contact_email');
        $this->getJson('/api/projects/'.$p->id.'/reports')->assertJsonPath('total', 1);
        $this->getJson('/api/projects/'.$p->id.'/reports/'.$private->id)->assertNotFound();
        $this->getJson('/api/projects/'.$p->id.'/reports/'.$r->id)->assertOk()->assertJsonPath('data.history', [])->assertJsonPath('data.allowed_transitions', []);
        $this->getJson('/api/projects/'.$p->id.'/activity')->assertForbidden();
        $this->postJson('/api/projects/'.$p->id.'/reports', $this->reportPayload())->assertForbidden();
        $this->getJson('/api/clients/'.$p->client_id)->assertOk()->assertJsonPath('data.reporting_periods_count', 1);
        $r->update(['status' => 'archived']);
        $this->getJson('/api/projects/'.$p->id)->assertNotFound();
    }

    public function test_cross_project_report_ids_and_archived_work_are_rejected(): void
    {
        $u = $this->person();
        $p = $this->project($u);
        $other = $this->project($u, $this->client($u, 'Other'));
        $r = $this->report($u, $other);
        $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/reports/'.$r->id)->assertNotFound();
        $p->update(['status' => 'archived']);
        $this->postJson('/api/projects/'.$p->id.'/reports', $this->reportPayload())->assertStatus(409);
        $p->update(['status' => 'active']);
        $p->client->update(['status' => 'archived']);
        $this->postJson('/api/projects/'.$p->id.'/reports', $this->reportPayload())->assertStatus(409);
    }

    public function test_team_management_uses_setup_links_scoped_users_and_prevents_privilege_escalation(): void
    {
        Notification::fake();
        $admin = $this->person('admin');
        $foreign = $this->person('seo_executive');
        $r = $this->actingAs($admin)->postJson('/api/team', ['name' => 'Analyst', 'email' => 'analyst-new@example.test', 'role' => 'seo_executive'])->assertCreated()->assertJsonMissingPath('data.password')->assertJsonMissingPath('token');
        $id = $r->json('data.id');
        $u = User::findOrFail($id);
        $this->assertSame($admin->id, $u->managed_by);
        Notification::assertSentTo($u, ResetPassword::class);
        $this->postJson('/api/team', ['name' => 'Escalation', 'email' => 'no@example.test', 'role' => 'super_admin'])->assertUnprocessable();
        $this->getJson('/api/team/'.$foreign->id)->assertNotFound();
        $this->putJson('/api/team/'.$id, ['name' => 'Updated', 'email' => $u->email, 'role' => 'seo_manager', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson('/api/team/'.$id.'/password-reset')->assertStatus(409);
        $this->actingAs($foreign)->getJson('/api/team')->assertForbidden();
    }

    public function test_super_admin_self_demotion_is_rejected_and_legacy_admin_access_is_kept(): void
    {
        $u = $this->person();
        $this->actingAs($u)->putJson('/api/team/'.$u->id, ['name' => $u->name, 'email' => $u->email, 'role' => 'seo_executive', 'is_active' => false])->assertUnprocessable();
        $this->assertTrue($u->fresh()->is_super_admin);
        $this->assertTrue($u->fresh()->is_active);
    }

    public function test_legacy_hanns_projects_are_preserved_and_backfill_is_idempotent_on_sqlite(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $u = $this->person();
        $beforeUser = $u->fresh()->getRawOriginal();
        $p = Project::factory()->create(['user_id' => $u->id, 'name' => "Hann's Pharmacy", 'client_name' => "Hann's Pharmacy SEO", 'website' => 'https://hannspharmacy.com/']);
        $before = $p->getRawOriginal();
        $p2 = Project::factory()->create(['user_id' => $u->id, 'client_name' => "Hann's Pharmacy SEO"]);
        app(LegacyClientBackfill::class)->run();
        app(LegacyClientBackfill::class)->run();
        $this->assertDatabaseCount('clients', 1);
        $this->assertSame($p->fresh()->client_id, $p2->fresh()->client_id);
        foreach ($before as $field => $value) {
            if ($field !== 'client_id') {
                $this->assertEquals($value, $p->fresh()->getRawOriginal($field));
            }
        }
        $this->assertSame($beforeUser, $u->fresh()->getRawOriginal());
        $this->actingAs($u)->getJson('/api/projects/'.$p->id)->assertOk()->assertJsonPath('data.client.name', "Hann's Pharmacy");
    }

    public function test_demotion_revokes_assigned_administrator_access(): void
    {
        $super = $this->person();
        $admin = $this->person('admin');
        $c = $this->client($super);
        $c->admins()->attach($admin);
        $p = $this->project($super, $c);
        $this->actingAs($admin)->getJson('/api/projects/'.$p->id)->assertOk();
        $admin->forceFill(['role' => 'seo_executive'])->save();
        auth()->forgetGuards();
        $this->actingAs($admin->fresh());
        $this->getJson('/api/projects/'.$p->id)->assertNotFound();
        $this->getJson('/api/clients/'.$c->id)->assertNotFound();
        $this->getJson('/api/team')->assertForbidden();
    }

    public function test_executive_ownership_does_not_grant_project_management(): void
    {
        $exec = $this->person('seo_executive');
        $p = $this->project($exec);
        $this->actingAs($exec)->getJson('/api/projects/'.$p->id)->assertOk()->assertJsonPath('data.permissions.manage', false)->assertJsonPath('data.permissions.work_reports', true);
        $this->putJson('/api/projects/'.$p->id, ['name' => 'No', 'client_id' => $p->client_id, 'website' => $p->website])->assertForbidden();
    }
}
