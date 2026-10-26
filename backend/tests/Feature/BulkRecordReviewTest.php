<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportSummaryController;
use App\Models\Client;
use App\Models\NormalizationRun;
use App\Models\NormalizedRecord;
use App\Models\Project;
use App\Models\ReportDocument;
use App\Models\ReportingPeriod;
use App\Models\User;
use App\Services\RecordReview;
use App\Services\ReportNormalization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BulkRecordReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private ReportingPeriod $report;

    private ReportDocument $doc;

    private NormalizationRun $run;

    private string $base;

    private string $content;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = User::factory()->create(['is_super_admin' => true, 'role' => 'super_admin']);
        $client = new Client(['name' => 'Bulk client', 'status' => 'active']);
        $client->forceFill(['user_id' => $this->user->id, 'identity_key' => hash('sha256', 'bulk')])->save();
        $this->project = Project::factory()->create(['user_id' => $this->user->id, 'client_id' => $client->id]);
        $this->report = $this->project->reports()->create(['title' => 'Bulk August', 'reporting_month' => '2026-08', 'kind' => 'monthly', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'draft', 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
        Storage::disk('local')->put('bulk.pdf', 'private source');
        $this->doc = ReportDocument::create(['project_id' => $this->project->id, 'reporting_period_id' => $this->report->id, 'original_filename' => 'source.pdf', 'stored_filename' => 'bulk.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'file_size' => 14, 'checksum' => hash('sha256', 'source'), 'active_checksum' => hash('sha256', 'source'), 'storage_path' => 'bulk.pdf', 'uploaded_by' => $this->user->id, 'extraction_status' => 'extracted']);
        $extraction = $this->doc->runs()->create(['status' => 'extracted']);
        $this->doc->update(['current_run_id' => $extraction->id]);
        $this->content = $extraction->content()->create(['document_id' => $this->doc->id, 'ordinal' => 0, 'content_type' => 'text', 'original_text' => 'Event count 10', 'source_location' => ['page' => 1], 'confidence_status' => 'native'])->id;
        $engine = app(ReportNormalization::class);
        $snapshot = $engine->snapshot($this->report);
        $this->run = NormalizationRun::create(['project_id' => $this->project->id, 'reporting_period_id' => $this->report->id, 'created_by' => $this->user->id, 'status' => 'completed', 'fingerprint' => $engine->fingerprint($this->report, $snapshot), 'input_snapshot' => $snapshot]);
        $this->base = '/api/projects/'.$this->project->id.'/reports/'.$this->report->id.'/data';
        $this->actingAs($this->user);
    }

    private function row(string $name = 'contact'): NormalizedRecord
    {
        $data = ['event_name' => $name, 'count' => 10];
        $row = NormalizedRecord::create(['project_id' => $this->project->id, 'reporting_period_id' => $this->report->id, 'normalization_run_id' => $this->run->id, 'kind' => 'event_metrics', 'section_key' => 'key_events', 'source_key' => hash('sha256', $name), 'source_data' => $data, 'raw_value' => $data, 'source_hash' => hash('sha256', json_encode($data)), 'confidence' => 'source']);
        $row->sources()->create(['normalization_run_id' => $this->run->id, 'document_id' => $this->doc->id, 'extraction_run_id' => $this->doc->current_run_id, 'content_id' => $this->content, 'reference_key' => hash('sha256', $name), 'location' => ['page' => 1], 'quote' => 'Event count 10', 'raw_value' => 10]);

        return $row;
    }

    private function verify(NormalizedRecord $row, array $resolutions = [])
    {
        return $this->postJson($this->base.'/records/'.$row->id.'/verification', ['revision' => $row->fresh()->revision, 'confirmed' => true, 'reason' => 'Compared saved values against original source.', 'resolutions' => $resolutions]);
    }

    private function payload(array $rows, string $decision = 'approved'): array
    {
        return ['record_ids' => array_map(fn ($r) => $r->id, $rows), 'revisions' => collect($rows)->mapWithKeys(fn ($r) => [$r->id => $r->fresh()->revision])->all(), 'decision' => $decision, 'reason' => 'Human evidence review completed for selected records.', 'confirmed' => true, 'request_key' => (string) Str::uuid()];
    }

    public function test_partial_bulk_results_require_explicit_verification_and_create_complete_audit(): void
    {
        $one = $this->row();
        $two = $this->row('phone');
        $this->getJson($this->base.'/records')->assertJsonPath('data.0.eligibility.verified', false);
        $this->verify($one)->assertOk()->assertJsonPath('data.eligible', true);
        $payload = $this->payload([$one, $two]);
        $this->postJson($this->base.'/records/bulk-preview', $payload)->assertOk()->assertJsonPath('data.eligible_count', 1)->assertJsonPath('data.excluded_count', 1);
        $this->assertDatabaseCount('record_corrections', 0);
        $this->postJson($this->base.'/records/bulk-review', $payload)->assertOk()->assertJsonPath('data.eligible_count', 1)->assertJsonPath('data.excluded_count', 1);
        $this->assertSame('approved', $one->fresh()->review_status);
        $this->assertSame('pending', $two->fresh()->review_status);
        $audit = DB::table('record_corrections')->first();
        $meta = json_decode($audit->review_metadata, true);
        $this->assertSame('bulk', $audit->approval_method);
        $this->assertSame('pending', $meta['previous_decision']);
        $this->assertSame($this->report->id, $meta['report_id']);
        $this->assertCount(1, $meta['source_references']);
        $this->assertSame($one->source_data, $meta['source_data']);
        $this->assertSame(2, $meta['revision']);
    }

    public function test_ocr_and_inferred_data_need_recorded_inspection_and_specific_warning_resolution(): void
    {
        $row = $this->row();
        $row->update(['confidence' => 'ocr']);
        $this->doc->update(['extraction_status' => 'partially_extracted']);
        $this->doc->currentRun()->update(['status' => 'partially_extracted']);
        $this->run->update(['fingerprint' => app(ReportNormalization::class)->fingerprint($this->report, app(ReportNormalization::class)->snapshot($this->report))]);
        $id = (string) Str::ulid();
        DB::table('validation_warnings')->insert(['id' => $id, 'normalization_run_id' => $this->run->id, 'document_id' => $this->doc->id, 'code' => 'uncertain_ocr', 'severity' => 'warning', 'message' => 'Compare OCR to source.', 'source' => '{}']);
        $this->verify($row)->assertUnprocessable();
        $this->postJson($this->base.'/records/bulk-review', $this->payload([$row]))->assertJsonPath('data.eligible_count', 0);
        $this->verify($row, [['warning_id' => $id, 'reason' => 'Checked the original image; the count is clearly 10.']])->assertOk()->assertJsonPath('data.eligible', true);
        $row->update(['confidence' => 'inferred']);
        $this->getJson($this->base.'/records/'.$row->id)->assertJsonPath('data.eligibility.eligible', true);
        Storage::disk('local')->delete('bulk.pdf');
        $this->postJson($this->base.'/records/bulk-review', $this->payload([$row]))->assertJsonPath('data.eligible_count', 0);
    }

    public function test_malformed_foreign_and_unauthorized_requests_make_no_changes(): void
    {
        $row = $this->row();
        $this->verify($row)->assertOk();
        $payload = $this->payload([$row]);
        $this->postJson($this->base.'/records/bulk-review', [...$payload, 'record_ids' => ['bad']])->assertUnprocessable();
        $this->postJson($this->base.'/records/bulk-review', [...$payload, 'record_ids' => [$row->id, $row->id]])->assertUnprocessable();
        $foreign = (string) Str::ulid();
        $bad = $payload;
        $bad['record_ids'][] = $foreign;
        $bad['revisions'][$foreign] = 1;
        $this->postJson($this->base.'/records/bulk-review', $bad)->assertNotFound();
        $other = $this->project->reports()->create(['title' => 'Other', 'reporting_month' => '2026-09', 'kind' => 'monthly', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'draft', 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
        $this->postJson(str_replace($this->report->id, $other->id, $this->base).'/records/bulk-review', $payload)->assertNotFound();
        $executive = User::factory()->create(['role' => 'seo_executive', 'is_super_admin' => false]);
        $this->project->members()->attach($executive->id, ['assignment_role' => 'member']);
        $this->actingAs($executive)->postJson($this->base.'/records/bulk-review', $payload)->assertForbidden();
        $this->postJson($this->base.'/records/'.$row->id.'/verification', [])->assertForbidden();
        $outsider = User::factory()->create(['role' => 'seo_manager', 'is_super_admin' => false]);
        $this->actingAs($outsider)->postJson($this->base.'/records/bulk-review', $payload)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->postJson($this->base.'/records/bulk-review', $payload)->assertUnauthorized();
        $this->assertDatabaseCount('record_corrections', 0);
        $this->assertSame('pending', $row->fresh()->review_status);
    }

    public function test_duplicate_requests_preserve_existing_decisions_and_audit_once(): void
    {
        $row = $this->row();
        $this->verify($row)->assertOk();
        $payload = $this->payload([$row]);
        $first = $this->postJson($this->base.'/records/bulk-review', $payload)->assertOk()->json();
        $this->assertSame($first, $this->postJson($this->base.'/records/bulk-review', $payload)->assertOk()->json());
        $this->assertDatabaseCount('record_corrections', 1);
        $this->assertDatabaseCount('bulk_review_requests', 1);
        $this->postJson($this->base.'/records/bulk-review', [...$payload, 'reason' => 'Changed request payload should fail.'])->assertConflict();
        $before = $row->fresh()->getRawOriginal();
        $this->postJson($this->base.'/records/bulk-review', $this->payload([$row]))->assertOk()->assertJsonPath('data.eligible_count', 0);
        $this->assertSame($before, $row->fresh()->getRawOriginal());
        $this->assertDatabaseCount('record_corrections', 1);
    }

    public function test_revision_conflicts_are_excluded_and_stale_reports_are_rejected(): void
    {
        $one = $this->row();
        $two = $this->row('second');
        $this->verify($one)->assertOk();
        $this->verify($two)->assertOk();
        $payload = $this->payload([$one, $two]);
        $one->increment('revision');
        $this->postJson($this->base.'/records/bulk-review', $payload)->assertOk()->assertJsonPath('data.eligible_count', 1)->assertJsonPath('data.excluded_count', 1);
        $this->assertSame('pending', $one->fresh()->review_status);
        $this->doc->update(['checksum' => hash('sha256', 'changed')]);
        $this->postJson($this->base.'/records/bulk-review', $this->payload([$one]))->assertConflict();
    }

    public function test_individual_review_uses_same_eligibility_and_corrections_invalidate_verification(): void
    {
        $row = $this->row();
        $input = ['review_status' => 'approved', 'reason' => 'Compared against original evidence.', 'revision' => 1];
        $this->patchJson($this->base.'/records/'.$row->id, $input)->assertUnprocessable();
        $this->verify($row)->assertOk();
        $this->patchJson($this->base.'/records/'.$row->id, $input)->assertOk();
        $this->assertDatabaseHas('record_corrections', ['approval_method' => 'individual']);
        $this->patchJson($this->base.'/records/'.$row->id, ['review_status' => 'pending', 'reason' => 'Correcting source interpretation.', 'revision' => 2, 'data' => ['count' => 12]])->assertOk();
        $this->getJson($this->base.'/records/'.$row->id)->assertJsonPath('data.eligibility.verified', false);
        $this->postJson($this->base.'/records/bulk-review', $this->payload([$row], 'rejected'))->assertJsonPath('data.eligible_count', 1);
        $this->verify($row)->assertOk();
        $this->postJson($this->base.'/records/bulk-review', $this->payload([$row]))->assertJsonPath('data.eligible_count', 0);
    }

    public function test_filters_pagination_and_existing_approval_preservation(): void
    {
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $rows[] = $this->row('Event '.$i);
        }
        $rows[0]->update(['review_status' => 'approved']);
        $before = $rows[0]->fresh()->getRawOriginal();
        $this->verify($rows[1])->assertOk();
        $this->getJson($this->base.'/records?page=1')->assertJsonCount(25, 'data')->assertJsonPath('total', 30);
        $this->getJson($this->base.'/records?page=2')->assertJsonCount(5, 'data');
        $this->getJson($this->base.'/records?eligibility=eligible')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $rows[1]->id);
        $this->getJson($this->base.'/records?status=approved')->assertJsonCount(1, 'data');
        $this->getJson($this->base.'/records?search=Event%2029')->assertJsonCount(1, 'data');
        $this->getJson($this->base.'/records?eligibility=invalid')->assertUnprocessable();
        $this->assertSame($before, $rows[0]->fresh()->getRawOriginal());
    }

    public function test_unresolvable_missing_evidence_invalid_values_and_conflicts_cannot_be_waived(): void
    {
        $row = $this->row();
        $row->update(['source_data' => ['event_name' => 'bad', 'count' => -1]]);
        $this->verify($row)->assertUnprocessable();
        $row->update(['source_data' => ['event_name' => 'good', 'count' => 10]]);
        $row->sources()->delete();
        $this->verify($row)->assertUnprocessable();
        $a = $this->row('metric a');
        $b = $this->row('metric b');
        foreach ([$a, $b] as $i => $r) {
            $r->update(['kind' => 'report_metrics', 'source_data' => ['metric_key' => 'ga4.total_users', 'value' => $i + 1, 'unit' => 'count', 'definition' => 'ga4']]);
        }
        $this->verify($a)->assertUnprocessable();
    }

    public function test_unexpected_audit_failure_rolls_back_entire_batch(): void
    {
        $a = $this->row();
        $b = $this->row('second');
        $this->verify($a)->assertOk();
        $this->verify($b)->assertOk();
        $real = app(RecordReview::class);
        $calls = 0;
        $mock = \Mockery::mock(RecordReview::class, [$real->access, $real->engine])->makePartial();
        $mock->shouldReceive('audit')->twice()->andReturnUsing(function (...$args) use ($real, &$calls) {
            if (++$calls === 2) {
                throw new \RuntimeException('Simulated audit storage failure');
            }
            $real->audit(...$args);
        });
        $this->app->instance(RecordReview::class, $mock);
        $this->postJson($this->base.'/records/bulk-review', $this->payload([$a, $b]))->assertStatus(500);
        $this->assertSame('pending', $a->fresh()->review_status);
        $this->assertSame('pending', $b->fresh()->review_status);
        $this->assertDatabaseCount('record_corrections', 0);
        $this->assertDatabaseCount('bulk_review_requests', 0);
    }

    public function test_real_cross_client_record_and_invalid_confirmation_are_rejected_atomically(): void
    {
        $row = $this->row();
        $this->verify($row)->assertOk();
        $foreign = $this->row('foreign');
        $client = $this->project->client->replicate();
        $client->identity_key = hash('sha256', 'other client');
        $client->save();
        $project = Project::factory()->create(['user_id' => $this->user->id, 'client_id' => $client->id]);
        $report = $this->report->replicate();
        $report->project_id = $project->id;
        $report->save();
        $foreign->update(['project_id' => $project->id, 'reporting_period_id' => $report->id]);
        $this->postJson($this->base.'/records/bulk-review', $this->payload([$row, $foreign]))->assertNotFound();
        foreach ([['confirmed' => false], ['decision' => 'published'], ['reason' => 'short'], ['request_key' => 'bad-key'], ['revisions' => []]] as $bad) {
            $this->postJson($this->base.'/records/bulk-review', array_replace($this->payload([$row]), $bad))->assertUnprocessable();
        }
        $this->assertDatabaseCount('record_corrections', 0);
        $this->assertSame('pending', $row->fresh()->review_status);
    }

    public function test_informational_warnings_do_not_block_and_changed_warning_context_invalidates_verification(): void
    {
        $row = $this->row();
        $id = (string) Str::ulid();
        DB::table('validation_warnings')->insert(['id' => $id, 'normalization_run_id' => $this->run->id, 'normalized_record_id' => $row->id, 'code' => 'duplicate_metric', 'severity' => 'warning', 'message' => 'Identical repeated source observation.', 'source' => '{}']);
        $this->verify($row)->assertOk()->assertJsonPath('data.eligible', true);
        $this->verify($row)->assertOk();
        $this->assertDatabaseCount('record_verifications', 1);
        DB::table('validation_warnings')->where('id', $id)->update(['severity' => 'blocking', 'message' => 'New material source concern.']);
        $this->getJson($this->base.'/records/'.$row->id)->assertJsonPath('data.eligibility.verified', false)->assertJsonPath('data.eligibility.eligible', false);
    }

    public function test_draft_dashboard_preserves_mixed_decisions_and_labels_unverified_values_provisional(): void
    {
        $a = $this->row('Approved verified');
        $b = $this->row('Approved unverified');
        $c = $this->row('Pending verified');
        $this->verify($a)->assertOk();
        $this->verify($c)->assertOk();
        $a->update(['review_status' => 'approved']);
        $b->update(['review_status' => 'approved']);
        $before = NormalizedRecord::orderBy('id')->get()->map->getRawOriginal()->all();
        $url = str_replace('/data', '/dashboard', $this->base);
        $result = $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.mode', 'internal_draft_preview')->assertJsonPath('data.report.status', 'draft')->assertJsonPath('data.coverage.approved', 2)->assertJsonPath('data.coverage.provisional', 2)->json('data.records');
        $byId = collect($result)->keyBy('id');
        $this->assertFalse($byId[$a->id]['provisional']);
        $this->assertTrue($byId[$b->id]['provisional']);
        $this->assertTrue($byId[$c->id]['provisional']);
        $this->assertSame($before, NormalizedRecord::orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame('draft', $this->report->fresh()->status);
        $this->getJson($url.'?include_provisional=0')->assertJsonCount(1, 'data.records')->assertJsonPath('data.records.0.id', $a->id);
        $this->assertDatabaseCount('record_corrections', 0);
    }

    public function test_dashboard_reads_corrections_approvals_and_stale_sources_without_cached_values(): void
    {
        $row = $this->row();
        $url = str_replace('/data', '/dashboard', $this->base);
        $this->getJson($url)->assertJsonPath('data.records.0.data.count', 10)->assertJsonPath('data.records.0.provisional', true);
        $this->patchJson($this->base.'/records/'.$row->id, ['review_status' => 'pending', 'revision' => 1, 'reason' => 'Corrected against source evidence.', 'data' => ['count' => 12]])->assertOk();
        $this->getJson($url)->assertJsonPath('data.records.0.data.count', 12);
        $this->verify($row)->assertOk();
        $this->postJson($this->base.'/records/bulk-review', $this->payload([$row]))->assertOk()->assertJsonPath('data.eligible_count', 1);
        $this->getJson($url)->assertJsonPath('data.records.0.provisional', false)->assertJsonPath('data.records.0.review_status', 'approved');
        $this->doc->update(['checksum' => hash('sha256', 'new original')]);
        $this->getJson($url)->assertJsonPath('data.stale', true)->assertJsonPath('data.records.0.provisional', true)->assertJsonPath('data.publication.allowed', false);
    }

    public function test_publishing_and_client_sharing_recheck_readiness_and_allow_revocation(): void
    {
        $row = $this->row();
        $this->verify($row)->assertOk();
        $this->report->update(['status' => 'approved']);
        $url = str_replace('/data', '', $this->base);
        $viewer = User::factory()->create(['role' => 'client_viewer', 'is_super_admin' => false]);
        $this->patchJson($url.'/status', ['status' => 'published', 'version' => 1])->assertConflict();
        $this->putJson($url.'/shares', ['user_ids' => [$viewer->id]])->assertConflict();
        $this->assertSame('approved', $this->report->fresh()->status);
        $this->putJson($url.'/shares', ['user_ids' => []])->assertOk();
        $this->report->update(['status' => 'draft']);
        $this->postJson($this->base.'/records/bulk-review', $this->payload([$row]))->assertOk()->assertJsonPath('data.eligible_count', 1);
        $this->report->update(['status' => 'approved']);
        $this->putJson($url.'/shares', ['user_ids' => [$viewer->id]])->assertConflict();
        $this->patchJson($url.'/status', ['status' => 'published', 'version' => 1])->assertConflict();
        // Stage 6 requires complete readiness, approved commentary and an immutable publication.
        $this->report->update(['status' => 'published']);
        $this->report->viewers()->attach($viewer->id, ['shared_by' => $this->user->id, 'expires_at' => now()->addDay()]);
        // This endpoint remains an internal preview even for an explicitly shared published report.
        $this->actingAs($viewer)->getJson($url.'/dashboard')->assertForbidden();
    }

    public function test_dashboard_warnings_evidence_and_conflicts_are_preserved_and_block_publication(): void
    {
        $a = $this->row('metric a');
        $b = $this->row('metric b');
        foreach ([$a, $b] as $i => $row) {
            $row->update(['kind' => 'report_metrics', 'review_status' => 'approved', 'source_data' => ['metric_key' => 'ga4.total_users', 'label' => 'Total users', 'value' => $i + 10, 'unit' => 'count', 'definition' => 'ga4']]);
        }
        $id = (string) Str::ulid();
        DB::table('validation_warnings')->insert(['id' => $id, 'normalization_run_id' => $this->run->id, 'document_id' => $this->doc->id, 'code' => 'date_range_mismatch', 'severity' => 'warning', 'message' => 'Material source date difference.', 'source' => '{}']);
        $before = DB::table('validation_warnings')->get()->toJson();
        $url = str_replace('/data', '/dashboard', $this->base);
        $this->getJson($url)->assertJsonPath('data.kpis.0.value', null)->assertJsonPath('data.kpis.0.conflict', true)->assertJsonPath('data.publication.allowed', false)->assertJsonPath('data.publication.blocking_warning_count', 1)->assertJsonPath('data.records.0.sources.0.document_id', $this->doc->id)->assertJsonPath('data.warnings.0.id', $id);
        $this->getJson($url.'?include_provisional=0')->assertJsonCount(0, 'data.records')->assertJsonCount(1, 'data.warnings')->assertJsonPath('data.publication.allowed', false);
        $this->assertSame($before, DB::table('validation_warnings')->get()->toJson());
    }

    public function test_dashboard_empty_states_input_validation_and_internal_project_isolation(): void
    {
        $url = str_replace('/data', '/dashboard', $this->base);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.records')->assertJsonPath('data.publication.allowed', false);
        $this->getJson($url.'?include_provisional=invalid')->assertUnprocessable();
        $outsider = User::factory()->create(['role' => 'seo_executive', 'is_super_admin' => false]);
        $this->actingAs($outsider)->getJson($url)->assertNotFound();
        $this->project->members()->attach($outsider->id, ['assignment_role' => 'member']);
        $this->getJson($url)->assertOk();
        $this->getJson(str_replace($this->report->id, (string) Str::ulid(), $url))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
    }

    public function test_summary_comments_are_append_only_scoped_and_never_change_metrics_or_decisions(): void
    {
        $row = $this->row();
        $url = str_replace('/data', '/summary', $this->base);
        $before = $row->fresh()->getRawOriginal();
        $reportBefore = $this->report->fresh()->getRawOriginal();
        $summary = $this->getJson($url)->assertOk()->assertJsonPath('data.revision', 0)->json('data');
        $this->assertDatabaseCount('report_summary_revisions', 0);
        $sections = array_fill_keys(ReportSummaryController::SECTIONS, '');
        $sections['key_events'] = 'The listed event count was compared with the source screenshot. No lead quality claim is made.';
        $payload = ['revision' => 0, 'source_fingerprint' => $summary['source_fingerprint'], 'sections' => $sections, 'reason' => 'Added source-grounded executive commentary.', 'confirmed' => true];
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('data.sections.key_events', $sections['key_events']);
        $this->postJson($url, $payload)->assertConflict();
        $this->postJson($url, [...$payload, 'revision' => 1])->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertDatabaseCount('report_summary_revisions', 2);
        $this->assertSame($before, $row->fresh()->getRawOriginal());
        $this->assertSame($reportBefore, $this->report->fresh()->getRawOriginal());
        $this->assertDatabaseCount('record_corrections', 0);
        $this->getJson($url)->assertJsonCount(2, 'data.history');
    }

    public function test_summary_edit_permissions_validation_and_source_revision_conflicts(): void
    {
        $row = $this->row();
        $url = str_replace('/data', '/summary', $this->base);
        $summary = $this->getJson($url)->assertOk()->json('data');
        $payload = ['revision' => 0, 'source_fingerprint' => $summary['source_fingerprint'], 'sections' => array_fill_keys(ReportSummaryController::SECTIONS, ''), 'reason' => 'Reviewed original evidence and revised notes.', 'confirmed' => true];
        $this->postJson($url, [...$payload, 'confirmed' => false])->assertUnprocessable();
        $this->postJson($url, [...$payload, 'sections' => ['unexpected' => 'Injected field']])->assertUnprocessable();
        $row->increment('revision');
        $this->postJson($url, $payload)->assertConflict();
        $exec = User::factory()->create(['role' => 'seo_executive', 'is_super_admin' => false]);
        $this->project->members()->attach($exec->id, ['assignment_role' => 'member']);
        $this->actingAs($exec)->getJson($url)->assertOk()->assertJsonPath('data.can_edit', true);
        $this->postJson($url, $payload)->assertConflict(); // Editing is permitted, but stale source fingerprints still fail closed.
        $outsider = User::factory()->create(['role' => 'seo_manager', 'is_super_admin' => false]);
        $this->actingAs($outsider)->getJson($url)->assertNotFound();
        $this->actingAs($this->user);
        $this->report->update(['status' => 'approved']);
        $this->postJson($url, $payload)->assertForbidden();
        $this->assertDatabaseCount('report_summary_revisions', 0);
    }

    public function test_dashboard_distinguishes_decisions_current_verification_and_exact_requirements(): void
    {
        $approved = $this->row('Approved without verification');
        $approved->update(['review_status' => 'approved']);
        $verified = $this->row('Verified pending');
        $this->verify($verified)->assertOk();
        $pending = $this->row('Pending unverified');
        $complete = $this->row('Approved and verified');
        $this->verify($complete)->assertOk();
        $complete->update(['review_status' => 'approved']);
        $url = str_replace('/data', '/dashboard', $this->base);
        $rows = collect($this->getJson($url)->assertOk()->json('data.records'))->keyBy('id');
        $this->assertSame('approved', $rows[$approved->id]['review_status']);
        $this->assertSame('not_recorded', $rows[$approved->id]['verification']['state']);
        $this->assertTrue($rows[$approved->id]['provisional']);
        $this->assertSame('human_verification_not_recorded', $rows[$approved->id]['outstanding_requirements'][0]['code']);
        $this->assertSame('pending', $rows[$verified->id]['review_status']);
        $this->assertTrue($rows[$verified->id]['human_verified']);
        $this->assertSame('approval_required', $rows[$verified->id]['outstanding_requirements'][0]['code']);
        $this->assertTrue($rows[$pending->id]['provisional']);
        $this->assertCount(2, $rows[$pending->id]['outstanding_requirements']);
        $this->assertFalse($rows[$complete->id]['provisional']);
        $this->assertSame([], $rows[$complete->id]['outstanding_requirements']);
        $warning = (string) Str::ulid();
        DB::table('validation_warnings')->insert(['id' => $warning, 'normalization_run_id' => $this->run->id, 'normalized_record_id' => $complete->id, 'code' => 'uncertain_ocr', 'severity' => 'warning', 'message' => 'Compare the reported count against the original image.', 'source' => '{}']);
        $before = $complete->fresh()->getRawOriginal();
        $rows = collect($this->getJson($url)->assertOk()->json('data.records'))->keyBy('id');
        $this->assertSame('approved', $rows[$complete->id]['review_status']);
        $this->assertSame('outdated', $rows[$complete->id]['verification']['state']);
        $this->assertTrue($rows[$complete->id]['provisional']);
        $this->assertSame($warning, $rows[$complete->id]['validation_warnings'][0]['id']);
        $this->assertSame(['human_verification_outdated', 'uncertain_ocr'], array_column($rows[$complete->id]['outstanding_requirements'], 'code'));
        $this->assertSame($before, $complete->fresh()->getRawOriginal());
        $this->assertDatabaseCount('record_corrections', 0);
        $this->assertDatabaseCount('record_verifications', 2);
    }

    public function test_dashboard_attributes_current_observation_sources_without_deleting_history(): void
    {
        $row = $this->row();
        $old = $this->doc->runs()->create(['status' => 'extracted']);
        $content = $old->content()->create(['document_id' => $this->doc->id, 'ordinal' => 0, 'content_type' => 'text', 'original_text' => 'Historical count 9', 'source_location' => ['page' => 9], 'confidence_status' => 'native']);
        $run = NormalizationRun::create(['project_id' => $this->project->id, 'reporting_period_id' => $this->report->id, 'created_by' => $this->user->id, 'status' => 'completed', 'fingerprint' => $this->run->fingerprint, 'input_snapshot' => $this->run->input_snapshot, 'created_at' => now()->subDay()]);
        $row->sources()->create(['normalization_run_id' => $run->id, 'document_id' => $this->doc->id, 'extraction_run_id' => $old->id, 'content_id' => $content->id, 'reference_key' => hash('sha256', 'historical'), 'location' => ['page' => 9], 'quote' => 'Historical count 9', 'raw_value' => 9]);
        $this->getJson(str_replace('/data', '/dashboard', $this->base))->assertOk()->assertJsonCount(1, 'data.records.0.sources')->assertJsonPath('data.records.0.sources.0.extraction_run_id', $this->doc->current_run_id);
        $this->assertSame(2, $row->sources()->count());
        $this->getJson($this->base.'/records/'.$row->id)->assertOk()->assertJsonCount(2, 'data.sources');
    }
}
