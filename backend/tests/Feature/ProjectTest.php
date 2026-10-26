<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['name' => 'Website SEO', 'client_name' => 'Example Client', 'website' => 'https://example.com', 'description' => 'Monthly SEO project'];
    }

    public function test_project_creation_uses_authenticated_owner_and_persists(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create();
        $response = $this->actingAs($owner)->postJson('/api/projects', $this->payload() + ['user_id' => $other->id])->assertCreated()->assertJsonPath('data.name', 'Website SEO')->assertJsonMissingPath('data.user_id');
        $this->assertDatabaseHas('projects', ['id' => $response->json('data.id'), 'user_id' => $owner->id]);
        $this->getJson('/api/projects/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.client_name', 'Example Client');
    }

    public function test_creation_without_a_start_date_defaults_to_monthly(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $id = $this->actingAs($owner)->postJson('/api/projects', $this->payload())
            ->assertCreated()->assertJsonPath('data.reporting_frequency', 'monthly')->json('data.id');
        $this->assertDatabaseHas('projects', ['id' => $id, 'start_date' => null, 'reporting_frequency' => 'monthly']);
    }

    public function test_edit_preserves_historical_date_and_existing_report_even_with_legacy_date_input(): void
    {
        $p = Project::factory()->create();
        $p->forceFill(['start_date' => '2020-02-17'])->save();
        $p->user->forceFill(['role' => 'seo_manager'])->save();
        $this->actingAs($p->user);
        $url = '/api/projects/'.$p->id.'/reports';
        $id = $this->postJson($url, ['title' => 'August 2026', 'reporting_month' => '2026-08'])
            ->assertCreated()->assertJsonPath('data.start_date', '2026-08-01')->assertJsonPath('data.end_date', '2026-08-31')->json('data.id');
        $report = \App\Models\ReportingPeriod::findOrFail($id)->getAttributes();
        foreach ([[], ['start_date' => null], ['start_date' => '2030-01-01']] as $legacy) {
            $this->putJson('/api/projects/'.$p->id, $this->payload() + $legacy)->assertOk();
            $this->assertSame('2020-02-17', $p->fresh()->getRawOriginal('start_date'));
            $this->assertSame($report, \App\Models\ReportingPeriod::findOrFail($id)->getAttributes());
        }
        $this->postJson($url, ['title' => 'Missing month', 'kind' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors('reporting_month');
        $this->postJson($url, ['title' => 'Invalid month', 'reporting_month' => '2026-13'])
            ->assertUnprocessable()->assertJsonValidationErrors('reporting_month');
        $this->assertDatabaseCount('reporting_periods', 1);
    }

    public function test_project_validation_rejects_invalid_fields_and_unsafe_urls(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/projects', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'client_name', 'website']);
        foreach (['javascript:alert(1)', 'ftp://example.com', 'not-a-url'] as $url) {
            $this->postJson('/api/projects', array_replace($this->payload(), ['website' => $url]))->assertUnprocessable()->assertJsonValidationErrors('website');
        }
        $this->postJson('/api/projects', array_replace($this->payload(), ['status' => 'published', 'name' => str_repeat('x', 151)]))->assertUnprocessable()->assertJsonValidationErrors(['name', 'status']);
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_projects_are_isolated_in_lists_overviews_and_searches(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        Project::factory()->create(['user_id' => $a->id, 'name' => 'Own project', 'status' => 'active']);
        $foreign = Project::factory()->create(['user_id' => $b->id, 'name' => 'Secret project']);
        $this->actingAs($a)->getJson('/api/projects')->assertOk()->assertJsonPath('total', 1)->assertJsonMissing(['id' => $foreign->id]);
        $this->getJson('/api/projects?search=Secret')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('total_projects', 1)->assertJsonPath('active_projects', 1)->assertJsonPath('archived_projects', 0)->assertJsonMissing(['id' => $foreign->id]);
    }

    public function test_foreign_project_ids_are_unavailable_for_read_update_and_dashboard(): void
    {
        $foreign = Project::factory()->create();
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/projects/'.$foreign->id)->assertNotFound();
        $this->getJson('/api/projects/'.$foreign->id.'/dashboard')->assertNotFound();
        $this->putJson('/api/projects/'.$foreign->id, $this->payload())->assertNotFound();
        $this->assertNotSame('Website SEO', $foreign->fresh()->name);
    }

    public function test_owner_can_edit_and_archive_without_transferring_ownership(): void
    {
        $p = Project::factory()->create();
        $p->user->forceFill(['role' => 'seo_manager'])->save();
        $this->actingAs($p->user);
        $this->putJson('/api/projects/'.$p->id, $this->payload() + ['status' => 'archived', 'user_id' => User::factory()->create()->id])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->assertSame($p->user_id, $p->fresh()->user_id);
        $this->getJson('/api/projects?status=active')->assertJsonPath('total', 0);
        $this->getJson('/api/projects?status=archived&search=Website')->assertJsonPath('total', 1);
    }

    public function test_pagination_is_bounded_and_input_is_validated(): void
    {
        $u = User::factory()->create();
        Project::factory()->count(13)->create(['user_id' => $u->id]);
        $this->actingAs($u)->getJson('/api/projects')->assertJsonCount(12, 'data')->assertJsonPath('total', 13)->assertJsonPath('last_page', 2);
        $this->getJson('/api/projects?page=2')->assertJsonCount(1, 'data');
        $this->getJson('/api/projects?page=-1&status=unknown')->assertUnprocessable()->assertJsonValidationErrors(['page', 'status']);
    }

    public function test_project_dashboard_has_empty_report_sections_and_no_fake_metrics(): void
    {
        $p = Project::factory()->create();
        $this->actingAs($p->user)->getJson('/api/projects/'.$p->id.'/dashboard')->assertOk()->assertJsonPath('project.id', $p->id)->assertJsonPath('reporting_status', 'not_enabled')->assertJsonCount(5, 'sections')->assertJsonPath('sections.0.status', 'awaiting_report')->assertJsonMissingPath('metrics');
    }

    public function test_guests_cannot_create_or_update_projects(): void
    {
        $p = Project::factory()->create();
        $this->postJson('/api/projects', $this->payload())->assertUnauthorized();
        $this->putJson('/api/projects/'.$p->id, $this->payload())->assertUnauthorized();
    }
}
