<?php

namespace Tests\Feature;

use App\Jobs\NormalizeReport;
use App\Models\Client;
use App\Models\NormalizationRun;
use App\Models\NormalizedRecord;
use App\Models\Project;
use App\Models\ReportDocument;
use App\Models\ReportingPeriod;
use App\Models\User;
use App\Services\ReportNormalization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NormalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private ReportingPeriod $report;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $this->user = User::factory()->create(['is_super_admin' => true, 'role' => 'super_admin']);
        $client = new Client(['name' => 'Normalization client', 'status' => 'active']);
        $client->forceFill(['user_id' => $this->user->id, 'identity_key' => hash('sha256', 'normalize')])->save();
        $this->project = Project::factory()->create(['user_id' => $this->user->id, 'client_id' => $client->id]);
        $this->report = $this->project->reports()->create(['title' => 'August source review', 'reporting_month' => '2026-08', 'kind' => 'monthly', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'draft', 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
        $this->base = '/api/projects/'.$this->project->id.'/reports/'.$this->report->id.'/data';
        $this->actingAs($this->user);
    }

    private function source(string $text = 'Total users\n800\nNew users\n700\nViews\n900'): ReportDocument
    {
        $text = "1 Aug 2026 - 31 Aug 2026\n".str_replace('\\n', "\n", $text);
        Storage::disk('local')->put('private.pdf', $text);
        $doc = ReportDocument::create(['project_id' => $this->project->id, 'reporting_period_id' => $this->report->id, 'original_filename' => 'arbitrary-source.pdf', 'stored_filename' => 'private.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'file_size' => 12, 'checksum' => hash('sha256', $text), 'active_checksum' => hash('sha256', $text), 'storage_path' => 'private.pdf', 'uploaded_by' => $this->user->id, 'extraction_status' => 'extracted']);
        $run = $doc->runs()->create(['status' => 'extracted', 'completed_at' => now()]);
        $doc->update(['current_run_id' => $run->id]);
        $run->content()->create(['document_id' => $doc->id, 'ordinal' => 0, 'content_type' => 'text', 'original_text' => $text, 'structured_json' => ['reading_order' => 'original_text_stream'], 'source_location' => ['page' => 1], 'confidence_status' => 'native']);

        return $doc->fresh();
    }

    private function normalize(): NormalizationRun
    {
        $id = $this->postJson($this->base.'/normalize')->assertStatus(202)->json('data.id');
        (new NormalizeReport($id))->handle(app(ReportNormalization::class));

        return NormalizationRun::findOrFail($id);
    }

    public function test_real_python_normalization_queue_sources_preview_and_safe_rerun(): void
    {
        $doc = $this->source();
        $before = $this->report->fresh()->getRawOriginal();
        $run = $this->normalize();
        $this->assertSame('completed', $run->status);
        Queue::assertPushed(NormalizeReport::class);
        $this->assertSame(3, NormalizedRecord::where('kind', 'report_metrics')->count());
        $this->getJson($this->base.'/preview')->assertOk()->assertJsonPath('data.kpis.0.value', 800)->assertJsonPath('data.stale', false);
        $row = NormalizedRecord::where('kind', 'report_metrics')->first();
        $this->getJson($this->base.'/records/'.$row->id)->assertJsonPath('data.sources.0.document_id', $doc->id);
        $ids = NormalizedRecord::orderBy('id')->pluck('id')->all();
        $refs = DB::table('metric_source_references')->count();
        $again = $this->normalize();
        $this->assertSame($ids, NormalizedRecord::orderBy('id')->pluck('id')->all());
        $this->assertSame($refs, DB::table('metric_source_references')->count());
        app(ReportNormalization::class)->execute($again->id);
        $this->assertSame($ids, NormalizedRecord::orderBy('id')->pluck('id')->all());
        $this->assertSame($before, $this->report->fresh()->getRawOriginal());
        $this->assertSame(3, DB::table('report_metrics')->count());
    }

    public function test_correction_approval_history_revision_and_rejection_survive_rerun(): void
    {
        $this->source();
        $this->normalize();
        $row = NormalizedRecord::where('kind', 'report_metrics')->first();
        $this->patchJson($this->base.'/records/'.$row->id, ['review_status' => 'approved', 'reason' => 'Checked against source', 'revision' => 1, 'data' => ['value' => 810], 'verify_source' => true])->assertOk()->assertJsonPath('data.data.value', 810);
        $this->assertDatabaseCount('record_corrections', 1);
        $this->assertSame(800, $row->fresh()->source_data['value']);
        $this->patchJson($this->base.'/records/'.$row->id, ['review_status' => 'rejected', 'reason' => 'Stale browser', 'revision' => 1])->assertConflict();
        $this->normalize();
        $row->refresh();
        $this->assertSame(810, $row->data['value']);
        $this->assertSame('approved', $row->review_status);
        $this->patchJson($this->base.'/records/'.$row->id, ['review_status' => 'rejected', 'reason' => 'Exclude from report', 'revision' => $row->revision])->assertOk();
        $this->getJson($this->base.'/preview?approved_only=1')->assertOk()->assertJsonCount(0, 'data.kpis');
    }

    public function test_source_changes_reopen_approval_preserve_override_and_detect_stale(): void
    {
        $doc = $this->source();
        $this->normalize();
        $row = NormalizedRecord::where('kind', 'report_metrics')->first();
        $this->patchJson($this->base.'/records/'.$row->id, ['review_status' => 'approved', 'reason' => 'Manual correction', 'revision' => 1, 'data' => ['value' => 810], 'verify_source' => true])->assertOk();
        $next = $doc->runs()->create(['status' => 'extracted']);
        $next->content()->create(['document_id' => $doc->id, 'ordinal' => 0, 'content_type' => 'text', 'original_text' => "1 Aug 2026 - 31 Aug 2026\nTotal users\n820\nNew users\n700\nViews\n900", 'structured_json' => ['reading_order' => 'original_text_stream'], 'source_location' => ['page' => 1], 'confidence_status' => 'native']);
        $doc->update(['current_run_id' => $next->id]);
        $this->getJson($this->base)->assertJsonPath('data.stale', true);
        $this->patchJson($this->base.'/records/'.$row->id, ['review_status' => 'approved', 'reason' => 'Stale source', 'revision' => 2])->assertConflict();
        $this->normalize();
        $row->refresh();
        $this->assertSame('pending', $row->review_status);
        $this->assertSame(820, $row->source_data['value']);
        $this->assertSame(810, $row->data['value']);
        $this->assertDatabaseHas('validation_warnings', ['code' => 'source_value_changed']);
    }

    public function test_conflicting_metrics_keep_observations_and_do_not_choose_or_sum(): void
    {
        $this->source('Total users\n800');
        $this->source('Total users\n900');
        $this->normalize();
        $this->assertDatabaseHas('validation_warnings', ['code' => 'conflicting_metric']);
        $this->getJson($this->base.'/preview')->assertJsonPath('data.kpis.0.value', null)->assertJsonPath('data.kpis.0.conflict', true);
    }

    public function test_cross_project_guests_viewers_executives_and_archived_reports_are_restricted(): void
    {
        $this->source();
        $this->normalize();
        $row = NormalizedRecord::first();
        $this->actingAs(User::factory()->create(['role' => 'seo_executive', 'is_super_admin' => false]))->getJson($this->base)->assertNotFound();
        $viewer = User::factory()->create(['role' => 'client_viewer', 'is_super_admin' => false]);
        $this->report->update(['status' => 'published']);
        $this->report->viewers()->attach($viewer->id, ['shared_by' => $this->user->id, 'expires_at' => now()->addDay()]);
        $this->actingAs($viewer)->getJson($this->base)->assertForbidden();
        $this->actingAs($this->user)->postJson($this->base.'/normalize')->assertForbidden();
        $this->report->update(['status' => 'draft']);
        $executive = User::factory()->create(['role' => 'seo_executive', 'is_super_admin' => false]);
        $this->project->members()->attach($executive->id, ['assignment_role' => 'member']);
        $this->actingAs($executive)->getJson($this->base)->assertOk();
        $this->patchJson($this->base.'/records/'.$row->id, ['review_status' => 'approved', 'revision' => 1, 'reason' => 'Executive approval'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->base)->assertUnauthorized();
    }

    public function test_invalid_corrections_and_cross_report_record_ids_are_rejected(): void
    {
        $this->source();
        $this->normalize();
        $row = NormalizedRecord::first();
        foreach ([['value' => -1], ['value' => .2], ['unit' => 'percent'], ['metric_key' => 'gsc.clicks']] as $data) {
            $this->patchJson($this->base.'/records/'.$row->id, ['review_status' => 'approved', 'reason' => 'Invalid edit', 'revision' => 1, 'data' => $data])->assertUnprocessable();
        }
        $other = $this->project->reports()->create(['title' => 'September', 'reporting_month' => '2026-09', 'kind' => 'monthly', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'draft', 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
        $url = '/api/projects/'.$this->project->id.'/reports/'.$other->id.'/data/records/'.$row->id;
        $this->getJson($url)->assertNotFound();
    }

    public function test_missing_python_and_active_attempt_have_real_failure_states(): void
    {
        $this->source();
        $id = $this->postJson($this->base.'/normalize')->assertStatus(202)->json('data.id');
        $this->postJson($this->base.'/normalize')->assertConflict();
        config(['documents.python' => 'missing-python-executable']);
        try {
            app(ReportNormalization::class)->execute($id);
            $this->fail('Expected interpreter failure');
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame('failed', NormalizationRun::find($id)->status);
        $this->assertDatabaseCount('normalized_records', 0);
    }

    public function test_processor_cannot_attach_foreign_source_evidence(): void
    {
        $doc = $this->source();
        $engine = app(ReportNormalization::class);
        $snapshot = $engine->snapshot($this->report);
        $run = NormalizationRun::create(['reporting_period_id' => $this->report->id, 'project_id' => $this->project->id, 'created_by' => $this->user->id, 'status' => 'processing', 'fingerprint' => $engine->fingerprint($this->report, $snapshot), 'input_snapshot' => $snapshot]);
        try {
            $engine->persist($run, $engine->manifest($run), ['records' => [['kind' => 'report_metrics', 'source_key' => str_repeat('a', 64), 'data' => [], 'sources' => [['document_id' => 'foreign', 'extraction_run_id' => 'foreign', 'content_id' => 'foreign']]]], 'warnings' => [], 'classifications' => []]);
            $this->fail('Expected foreign-source rejection');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('source reference', $e->getMessage());
        }
        $this->assertDatabaseCount('normalized_records', 0);
    }
}
