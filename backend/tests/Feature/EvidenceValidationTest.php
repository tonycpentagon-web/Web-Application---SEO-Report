<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\NormalizationRun;
use App\Models\NormalizedRecord;
use App\Models\Project;
use App\Models\ReportDocument;
use App\Models\ReportingPeriod;
use App\Models\User;
use App\Services\DraftCommentary;
use App\Services\EvidenceValidation;
use App\Services\ManagerReportApproval;
use App\Services\ReportNormalization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvidenceValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ReportingPeriod $p;

    private ReportDocument $doc;

    private NormalizationRun $run;

    private string $content;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->admin = User::factory()->create(['is_super_admin' => true]);
        $client = new Client(['name' => "Hann's Pharmacy", 'status' => 'active']);
        $client->forceFill(['user_id' => $this->admin->id, 'identity_key' => hash('sha256', 'validation-client')])->save();
        $project = Project::factory()->create(['user_id' => $this->admin->id, 'client_id' => $client->id, 'website' => 'https://hannspharmacy.com']);
        $this->p = $project->reports()->create(['title' => 'August 2026', 'reporting_month' => '2026-08', 'kind' => 'monthly', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'draft', 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id]);
        Storage::disk('local')->put('source.pdf', 'immutable original');
        $this->doc = ReportDocument::create(['project_id' => $project->id, 'reporting_period_id' => $this->p->id, 'original_filename' => 'source.pdf', 'stored_filename' => 'source.pdf', 'storage_path' => 'source.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'file_size' => 18, 'checksum' => hash('sha256', 'immutable original'), 'active_checksum' => hash('sha256', 'immutable original'), 'uploaded_by' => $this->admin->id, 'extraction_status' => 'partially_extracted']);
        $ex = $this->doc->runs()->create(['status' => 'partially_extracted']);
        $this->doc->update(['current_run_id' => $ex->id]);
        $this->content = $ex->content()->create(['document_id' => $this->doc->id, 'ordinal' => 0, 'content_type' => 'text', 'original_text' => "1 Aug 2026 - 31 Aug 2026\nTotal users 100\nClicks 20\nImpressions 200\nCTR 10%\nNew users 90\nPharmacy 1st Page\nhttps://example.test/activity\ncontact 7\nAI visibility Pharmacy appears", 'source_location' => ['page' => 1], 'confidence_status' => 'native'])->id;
        $engine = app(ReportNormalization::class);
        $snapshot = $engine->snapshot($this->p);
        $this->run = NormalizationRun::create(['project_id' => $project->id, 'reporting_period_id' => $this->p->id, 'created_by' => $this->admin->id, 'status' => 'completed', 'fingerprint' => $engine->fingerprint($this->p, $snapshot), 'input_snapshot' => $snapshot]);
        $this->base = '/api/projects/'.$project->id.'/reports/'.$this->p->id;
        $this->actingAs($this->admin);
    }

    private function row(string $kind = 'report_metrics', ?array $data = null, string $section = 'website_analytics'): NormalizedRecord
    {
        $data ??= ['metric_key' => 'ga4.total_users', 'label' => 'Total users', 'value' => 100, 'unit' => 'count', 'definition' => 'ga4', 'granularity' => 'reported_period_total'];
        $r = NormalizedRecord::create(['project_id' => $this->p->project_id, 'reporting_period_id' => $this->p->id, 'normalization_run_id' => $this->run->id, 'kind' => $kind, 'section_key' => $section, 'source_key' => hash('sha256', (string) Str::ulid()), 'source_data' => $data, 'raw_value' => $data, 'source_hash' => hash('sha256', json_encode($data)), 'confidence' => 'source']);
        $r->sources()->create(['normalization_run_id' => $this->run->id, 'document_id' => $this->doc->id, 'extraction_run_id' => $this->doc->current_run_id, 'content_id' => $this->content, 'reference_key' => hash('sha256', (string) Str::ulid()), 'location' => ['page' => 1], 'quote' => 'Original source retained', 'raw_value' => $data]);

        return $r;
    }

    private function evaluate(): array
    {
        return app(EvidenceValidation::class)->evaluate($this->p, $this->admin);
    }

    private function state(array $out, NormalizedRecord $r): string
    {
        return $out['records'][$r->id]['status'];
    }

    public function test_native_values_auto_validate_without_per_record_approval_or_fake_human_history(): void
    {
        $row = $this->row();
        $before = $row->fresh()->getAttributes();
        $out = $this->evaluate();
        $this->assertSame('AUTO_VALIDATED', $this->state($out, $row));
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertDatabaseCount('record_verifications', 0);
        $this->assertDatabaseCount('record_corrections', 0);
        $this->getJson($this->base.'/data/records?validation=AUTO_VALIDATED')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.review_status', 'pending');
        $this->getJson($this->base.'/data/records?validation=attention')->assertJsonPath('total', 0);
    }

    public function test_partial_and_ocr_document_warnings_do_not_block_native_rows_but_ocr_values_require_inspection(): void
    {
        $native = $this->row();
        $ocr = $this->row('event_metrics', ['event_name' => 'contact', 'count' => 7], 'key_events');
        $piece = $this->doc->currentRun->content()->create(['document_id' => $this->doc->id, 'ordinal' => 1, 'content_type' => 'ocr_text', 'original_text' => 'contact 7', 'source_location' => ['page' => 2], 'confidence_status' => 'ocr']);
        $ocr->sources()->update(['content_id' => $piece->id, 'location' => json_encode(['page' => 2])]);
        foreach (['partial_extraction', 'uncertain_ocr'] as $code) {
            DB::table('validation_warnings')->insert(['id' => (string) Str::ulid(), 'normalization_run_id' => $this->run->id, 'document_id' => $this->doc->id, 'code' => $code, 'severity' => 'warning', 'message' => $code, 'source' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        }
        $out = $this->evaluate();
        $this->assertSame('AUTO_VALIDATED', $this->state($out, $native));
        $this->assertSame('NEEDS_REVIEW', $this->state($out, $ocr));
        DB::table('document_extracted_content')->where('id', $piece->id)->update(['content_type' => 'text', 'confidence_status' => 'inferred']);
        $this->assertSame('NEEDS_REVIEW', $this->state($this->evaluate(), $ocr));
        $this->doc->currentRun->update(['warnings' => [['code' => 'page_limit', 'severity' => 'error', 'message' => 'Source page was truncated.']]]);
        $this->assertFalse(app(EvidenceValidation::class)->current($this->p)['current']);
        $this->assertContains('material_extraction_gap', array_column($this->evaluate()['summary']['document_issues'], 'code'));
        $this->assertFalse($out['summary']['data_ready']);
    }

    public function test_context_and_exact_duplicate_representations_are_retained_without_review_tasks(): void
    {
        $one = $this->row();
        $two = $this->row();
        $context = $this->row('unclassified', ['text' => 'Decorative header']);
        $out = $this->evaluate();
        $this->assertSame(1, $out['summary']['counts']['AUTO_VALIDATED']);
        $this->assertSame(2, $out['summary']['counts']['NOT_APPLICABLE']);
        $this->assertDatabaseCount('normalized_records', 3);
        $this->assertSame('NOT_APPLICABLE', $this->state($out, $context));
        $this->assertDatabaseCount('record_verifications', 0);
    }

    public function test_conflicts_missing_metrics_and_invalid_types_cannot_be_auto_approved(): void
    {
        $one = $this->row();
        $two = $this->row('report_metrics', ['metric_key' => 'ga4.total_users', 'label' => 'Total users', 'value' => 90, 'unit' => 'count', 'definition' => 'ga4']);
        $bad = $this->row('event_metrics', ['event_name' => 'contact', 'count' => -1], 'key_events');
        $out = $this->evaluate();
        foreach ([$one, $two, $bad] as $r) {
            $this->assertSame('INVALID', $this->state($out, $r));
        }$this->assertContains('gsc.clicks', $out['summary']['missing_metrics']);
        $this->assertFalse($out['summary']['data_ready']);
        $this->postJson($this->base.'/delivery/approve-report', ['confirmed' => true, 'version' => 1, 'fingerprint' => app(ManagerReportApproval::class)->context($this->p, $this->admin)['fingerprint'], 'reason' => 'Reviewed current report and its exceptions.'])->assertConflict();
        $this->assertSame('draft', $this->p->fresh()->status);
    }

    public function test_native_month_headers_and_sept_date_ranges_detect_wrong_month_without_relabelling_sources(): void
    {
        $r = $this->row();
        DB::table('document_extracted_content')->where('id', $this->content)->update(['original_text' => "SEO REPORT - S E P T '2 6\nTotal users 100"]);
        $out = $this->evaluate();
        $this->assertSame('INVALID', $this->state($out, $r));
        $this->assertSame('date_range_mismatch', $out['summary']['document_issues'][0]['code']);
        DB::table('document_extracted_content')->where('id', $this->content)->update(['original_text' => "29 Aug 2026 - 27 Sept 2026\nTotal users 100"]);
        $this->assertSame('INVALID', $this->state($this->evaluate(), $r));
        $this->assertSame('2026-08-01', $this->p->fresh()->start_date->format('Y-m-d'));
    }

    public function test_unsupported_values_unknown_period_changed_files_and_corrections_fail_closed(): void
    {
        $r = $this->row('event_metrics', ['event_name' => 'contact', 'count' => 999], 'key_events');
        $this->assertSame('NEEDS_REVIEW', $this->state($this->evaluate(), $r));
        $r->update(['manual_override' => ['count' => 7]]);
        $this->assertSame('NEEDS_REVIEW', $this->state($this->evaluate(), $r));
        $this->assertSame(['count' => 7], $r->fresh()->manual_override);
        Storage::disk('local')->put('source.pdf', 'tampered');
        $this->assertSame('INVALID', $this->state($this->evaluate(), $r));
    }

    public function test_changed_evidence_invalidates_a_current_assessment_and_reads_do_not_re_evaluate(): void
    {
        $r = $this->row();
        $this->evaluate();
        $r->update(['revision' => 2]);
        $this->assertFalse(app(EvidenceValidation::class)->current($this->p)['current']);
        $this->getJson($this->base.'/data')->assertOk()->assertJsonPath('data.validation.current', false);
        $this->assertDatabaseCount('report_validation_runs', 1);
    }

    public function test_admin_only_re_evaluation_preserves_history_and_refuses_published_reports(): void
    {
        $r = $this->row();
        $before = $r->fresh()->getAttributes();
        $this->getJson($this->base.'/data')->assertJsonPath('data.validation', null);
        $this->assertDatabaseCount('report_validation_runs', 0);
        $this->postJson($this->base.'/data/re-evaluate', ['confirmed' => true, 'version' => 1])->assertOk()->assertJsonPath('data.summary.counts.AUTO_VALIDATED', 1);
        $this->assertSame($before, $r->fresh()->getAttributes());
        $manager = User::factory()->create(['role' => 'seo_manager']);
        $this->p->project->members()->attach($manager->id, ['assignment_role' => 'manager']);
        $this->actingAs($manager)->postJson($this->base.'/data/re-evaluate', ['confirmed' => true, 'version' => 1])->assertForbidden();
        $this->actingAs($this->admin);
        $this->p->update(['status' => 'published']);
        $this->postJson($this->base.'/data/re-evaluate', ['confirmed' => true, 'version' => 1])->assertConflict();
        $this->assertDatabaseCount('report_validation_runs', 1);
    }

    public function test_gsc_ctr_is_reconciled_only_for_same_source_location_and_aggregation_scope(): void
    {
        $rows = [];
        foreach ([['clicks', 20, 'count'], ['impressions', 200, 'count'], ['ctr', 10, 'percent']] as [$key,$value,$unit]) {
            $rows[] = $this->row('report_metrics', ['metric_key' => 'gsc.'.$key, 'label' => $key, 'value' => $value, 'unit' => $unit, 'definition' => 'gsc', 'granularity' => 'reported_period_total'], 'search_console');
        }
        $out = $this->evaluate();
        foreach ($rows as $r) {
            $this->assertSame('AUTO_VALIDATED', $this->state($out, $r));
        }
        $rows[2]->update(['source_data' => ['metric_key' => 'gsc.ctr', 'label' => 'CTR', 'value' => 9, 'unit' => 'percent', 'definition' => 'gsc', 'granularity' => 'reported_period_total']]);
        $this->assertSame('INVALID', $this->state($this->evaluate(), $rows[2]));
        $rows[2]->sources()->update(['location' => json_encode(['page' => 2])]);
        $out = $this->evaluate();
        $this->assertNotContains('GSC CTR does not reconcile with same-scope clicks and impressions.', $out['records'][$rows[2]->id]['issues']);
    }

    public function test_final_manager_approval_covers_report_and_commentary_without_approving_individual_records(): void
    {
        $this->row();
        foreach ([['clicks', 20], ['impressions', 200]] as [$k,$v]) {
            $this->row('report_metrics', ['metric_key' => 'gsc.'.$k, 'label' => $k, 'value' => $v, 'unit' => 'count', 'definition' => 'gsc', 'granularity' => 'reported_period_total'], 'search_console');
        }
        $this->row('keyword_rankings', ['keyword' => 'Pharmacy', 'ranking_label' => '1st Page', 'category' => 'primary'], 'keyword_rankings');
        $this->row('seo_activities', ['category' => 'article_submission', 'url' => 'https://example.test/activity'], 'seo_activities');
        $this->row('event_metrics', ['event_name' => 'contact', 'count' => 7], 'key_events');
        // The screenshot requires a real separate human attestation; manager approval never fabricates one.
        $ai = $this->row('ai_visibility_evidence', ['query' => 'Pharmacy', 'platform' => null, 'brand_appearance' => 'appears'], 'ai_visibility');
        Storage::disk('local')->put('evidence.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1cAAAAASUVORK5CYII='));
        $asset = $this->doc->currentRun->assets()->create(['document_id' => $this->doc->id, 'asset_index' => 0, 'checksum' => hash('sha256', Storage::disk('local')->get('evidence.png')), 'asset_type' => 'image', 'storage_path' => 'evidence.png', 'mime_type' => 'image/png', 'file_size' => 68, 'metadata' => ['source' => ['page' => 1]]]);
        $ai->sources()->update(['asset_id' => $asset->id]);
        $this->evaluate();
        $this->postJson($this->base.'/data/records/'.$ai->id.'/verification', ['revision' => 1, 'confirmed' => true, 'reason' => 'Inspected original screenshot and compared brand appearance.', 'resolutions' => []])->assertOk();
        $validation = app(EvidenceValidation::class)->current($this->p);
        $this->assertTrue($validation['current']);
        $this->assertTrue($validation['summary']['data_ready'], json_encode($validation));
        $this->assertSame(6, $validation['summary']['counts']['AUTO_VALIDATED']);
        $this->assertSame(1, $validation['summary']['counts']['HUMAN_VERIFIED']);
        app(DraftCommentary::class)->generate($this->p, $this->admin);
        $context = app(ManagerReportApproval::class)->context($this->p, $this->admin);
        $this->assertTrue($context['can_approve'], json_encode($context));
        $this->postJson($this->base.'/delivery/approve-report', ['confirmed' => true, 'version' => 1, 'fingerprint' => $context['fingerprint'], 'reason' => 'Reviewed full report and source-backed executive commentary.'])->assertOk()->assertJsonPath('data.approved', true);
        $this->assertSame('approved', $this->p->fresh()->status);
        $this->assertDatabaseCount('record_verifications', 1);
        $this->assertSame(0, NormalizedRecord::where('review_status', 'approved')->count());
        $this->getJson($this->base.'/delivery')->assertOk()->assertJsonPath('data.readiness.eligible', true);
        $pub = $this->postJson($this->base.'/delivery/publish', ['confirmed' => true, 'version' => 2])->assertCreated()->json('data');
        $link = $this->postJson($this->base.'/delivery/shares', ['publication_id' => $pub['id'], 'access_mode' => 'public', 'public_confirmed' => true, 'label' => 'Validated report', 'allow_download' => false])->assertCreated()->json('data');
        $token = explode('#', $link['url'])[1];
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/public-report', ['X-Report-Token' => $token])->assertOk();
        $hash = DB::table('report_publications')->where('id', $pub['id'])->value('snapshot_hash');
        $this->actingAs($this->admin)->postJson($this->base.'/data/re-evaluate', ['confirmed' => true, 'version' => 3])->assertConflict();
        $this->assertSame($hash, DB::table('report_publications')->where('id', $pub['id'])->value('snapshot_hash'));
    }

    public function test_unknown_period_can_be_confirmed_once_per_source_without_fabricating_record_verification(): void
    {
        $r = $this->row();
        DB::table('document_extracted_content')->where('id', $this->content)->update(['original_text' => 'Total users 100']);
        $v = $this->evaluate();
        $this->assertSame('NEEDS_REVIEW', $this->state($v, $r));
        $this->postJson($this->base.'/data/source-scope/'.$this->doc->id, ['confirmed' => true, 'version' => 1, 'fingerprint' => $v['fingerprint'], 'reason' => 'Manager inspected source association with this client and reporting month.'])->assertOk()->assertJsonPath('data.summary.counts.AUTO_VALIDATED', 1);
        $this->assertDatabaseCount('report_source_scope_verifications', 1);
        $this->assertDatabaseCount('record_verifications', 0);
        $this->assertSame('pending', $r->fresh()->review_status);
        DB::table('document_extracted_content')->where('id', $this->content)->update(['original_text' => 'Total users 200']);
        $this->assertFalse(app(EvidenceValidation::class)->current($this->p)['current']);
        $this->assertSame('NEEDS_REVIEW', $this->state($this->evaluate(), $r));
    }

    public function test_scope_confirmation_cannot_waive_explicit_wrong_client_or_period(): void
    {
        $r = $this->row();
        DB::table('document_extracted_content')->where('id', $this->content)->update(['original_text' => "SEO REPORT - SEPT'26\nTotal users 100"]);
        $v = $this->evaluate();
        $this->postJson($this->base.'/data/source-scope/'.$this->doc->id, ['confirmed' => true, 'version' => 1, 'fingerprint' => $v['fingerprint'], 'reason' => 'Attempting to waive source scope mismatch is not permitted.'])->assertUnprocessable();
        $this->assertDatabaseCount('report_source_scope_verifications', 0);
        DB::table('document_extracted_content')->where('id', $this->content)->update(['original_text' => "1 Aug 2026 - 31 Aug 2026\nADDRESSED TO: Another Business Center\nTotal users 100"]);
        $v = $this->evaluate();
        $this->assertSame('INVALID', $this->state($v, $r));
        $this->assertSame('client_project_mismatch', $v['summary']['document_issues'][0]['code']);
    }

    public function test_bulk_and_individual_approval_cannot_waive_new_material_scope_exceptions(): void
    {
        $r = $this->row();
        DB::table('document_extracted_content')->where('id', $this->content)->update(['original_text' => 'Total users 100']);
        $this->evaluate();
        $this->postJson($this->base.'/data/records/'.$r->id.'/verification', ['revision' => 1, 'confirmed' => true, 'reason' => 'Inspected the saved value but reporting period is not printed.', 'resolutions' => []])->assertOk();
        $this->postJson($this->base.'/data/records/bulk-preview', ['record_ids' => [$r->id], 'revisions' => [$r->id => 1], 'decision' => 'approved'])->assertOk()->assertJsonPath('data.eligible_count', 0)->assertJsonPath('data.excluded_count', 1);
        $this->patchJson($this->base.'/data/records/'.$r->id, ['revision' => 1, 'review_status' => 'approved', 'reason' => 'Attempting to approve despite unresolved source scope.'])->assertUnprocessable();
        $this->assertSame('pending', $r->fresh()->review_status);
        $this->assertDatabaseCount('record_corrections', 0);
    }
    public function test_partial_month_requires_one_audited_assignment_without_faking_human_verification(): void
    {
        $row=$this->row();
        DB::table('document_extracted_content')->where('id',$this->content)->update(['original_text'=>"PREPARED FOR:\n1 Aug 2026 - 30 Aug 2026\nTotal users 100"]);
        $v=$this->evaluate();$this->assertSame('NEEDS_REVIEW',$this->state($v,$row));
        $state=$this->getJson($this->base.'/sharing')->assertOk()->json('data');
        $this->assertTrue($state['assessment']['eligible']);$this->assertCount(1,$state['assessment']['period_confirmations']);
        $payload=['version'=>1,'fingerprint'=>$state['assessment']['fingerprint'],'acknowledge_uncertainty'=>true];
        $this->postJson($this->base.'/delivery/share-report',$payload)->assertUnprocessable();
        $link=$this->postJson($this->base.'/delivery/share-report',[...$payload,'confirm_source_periods'=>true])->assertCreated()->json('data');
        $snapshot=\App\Models\ReportPublication::findOrFail($link['publication_id'])->snapshot;
        $this->assertSame(['2026-08-31'],$snapshot['source_periods'][0]['uncovered_dates']);$this->assertTrue($snapshot['source_periods'][0]['scope_confirmed']);
        $this->assertDatabaseCount('report_source_scope_verifications',1);$this->assertDatabaseCount('record_verifications',0);$this->assertSame('pending',$row->fresh()->review_status);
        $this->assertDatabaseHas('report_delivery_events',['action'=>'report.source_periods_confirmed','actor_id'=>$this->admin->id]);
    }

    public function test_refresh_checks_distinguishes_stale_validation_from_changed_sources_for_an_employee(): void
    {
        $this->row();$this->evaluate();
        DB::table('report_validation_runs')->update(['fingerprint'=>str_repeat('0',64)]);
        $employee=User::factory()->create(['role'=>'seo_executive','is_super_admin'=>false]);$this->p->project->members()->attach($employee->id,['assignment_role'=>'member']);$this->actingAs($employee);
        $state=$this->getJson($this->base.'/sharing')->assertOk()->json('data.assessment');
        $this->assertFalse($state['requires_regeneration']);$this->assertTrue($state['requires_validation_refresh']);$this->assertStringNotContainsString('Sources changed',implode(' ',$state['blockers']));
        $before=$this->doc->fresh()->getAttributes();
        $this->postJson($this->base.'/sharing/refresh',['version'=>1,'confirmed'=>true])->assertOk();
        $this->assertSame($before,$this->doc->fresh()->getAttributes());$this->assertTrue(app(EvidenceValidation::class)->current($this->p)['current']);$this->assertDatabaseCount('report_documents',1);
        $this->p->update(['requires_regeneration'=>true]);
        $this->postJson($this->base.'/sharing/refresh',['version'=>1,'confirmed'=>true])->assertConflict();
        $this->getJson($this->base.'/sharing')->assertJsonPath('data.assessment.requires_regeneration',true)->assertJsonPath('data.assessment.requires_validation_refresh',false);
    }

    public function test_snapshot_uses_current_period_evidence_instead_of_old_normalization_date_guesses(): void
    {
        $this->row();
        $this->run->update(['summary'=>['classifications'=>[['document_id'=>$this->doc->id,'source_dates'=>[['start'=>'2026-07-01','end'=>'2026-07-31']]]]]]);
        $this->evaluate();
        $snapshot=app(\App\Services\ReportDelivery::class)->snapshot($this->p,$this->admin,true);
        $this->assertSame([['start'=>'2026-08-01','end'=>'2026-08-31']],$snapshot['records'][0]['sources'][0]['source_period']);
    }

}
