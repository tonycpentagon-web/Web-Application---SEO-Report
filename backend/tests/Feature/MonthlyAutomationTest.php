<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportSummaryController;
use App\Jobs\GenerateMonthlyReport;
use App\Jobs\GenerateReportPdf;
use App\Jobs\ProcessReportDocument;
use App\Models\NormalizationRun;
use App\Models\NormalizedRecord;
use App\Models\Project;
use App\Models\ReportDocument;
use App\Models\ReportGeneration;
use App\Models\ReportingPeriod;
use App\Models\ReportPdfExport;
use App\Models\ReportPublication;
use App\Models\User;
use App\Services\DocumentAccess;
use App\Services\DraftCommentary;
use App\Services\PythonDocumentProcessor;
use App\Services\ReportNormalization;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MonthlyAutomationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Queue::fake();
        Storage::fake('local');
        $this->admin = User::factory()->create(['is_super_admin' => true, 'role' => 'super_admin']);
        $this->actingAs($this->admin);
        $client = $this->postJson('/api/clients', ['name' => 'Synthetic monthly client', 'company_name' => 'Synthetic Pharmacy', 'website' => 'https://synthetic.example', 'status' => 'active'])->assertCreated()->json('data.id');
        $this->project = Project::factory()->create(['client_id' => $client, 'user_id' => $this->admin->id]);
    }

    private function period(string $month): ReportingPeriod
    {
        $id = $this->postJson('/api/projects/'.$this->project->id.'/reports', ['title' => 'Synthetic '.$month, 'reporting_month' => $month, 'kind' => 'monthly'])->assertCreated()->json('data.id');

        return ReportingPeriod::findOrFail($id);
    }

    private function root(ReportingPeriod $p): string
    {
        return '/api/projects/'.$this->project->id.'/reports/'.$p->id;
    }

    private function upload(ReportingPeriod $p, string $month): void
    {
        $files = array_map(fn ($kind) => new UploadedFile(base_path('tests/fixtures/monthly/'.$month.'-'.$kind.'.pdf'), $kind.'.pdf', 'application/pdf', null, true), ['ga4', 'gsc', 'keywords', 'activities', 'ai', 'events']);
        $this->post($this->root($p).'/documents', ['files' => $files], ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(6, 'data');
    }

    private function process(ReportingPeriod $p): void
    {
        foreach ($p->documents()->get() as $doc) {
            (new ProcessReportDocument($doc->current_run_id))->handle(app(PythonDocumentProcessor::class));
        }
    }

    private function generate(ReportingPeriod $p): string
    {
        $id = $this->postJson($this->root($p).'/generation')->assertAccepted()->json('data.id');
        $this->postJson($this->root($p).'/generation')->assertAccepted()->assertJsonPath('data.id', $id);
        (new GenerateMonthlyReport($id))->handle(app(ReportNormalization::class), app(DocumentAccess::class));
        $this->assertSame('completed', ReportGeneration::findOrFail($id)->status);

        return $id;
    }

    private function publishReviewedSynthetic(ReportingPeriod $p): array
    {
        // Only synthetic fixture records in this isolated, in-memory test database.
        // Exercise the same explicit correction/verification/review APIs used by people.
        $root = $this->root($p);
        foreach (NormalizedRecord::where('reporting_period_id', $p->id)->where('active', true)->get() as $row) {
            $show = $this->getJson($root.'/data/records/'.$row->id)->assertOk()->json('data');
            $resolutions = collect($show['eligibility']['warnings'])->filter(fn ($w) => $w['blocking'])->map(fn ($w) => ['warning_id' => $w['id'], 'reason' => 'Compared the synthetic PDF fixture and confirmed this source association and coverage.'])->values()->all();
            $payload = ['revision' => $row->revision, 'review_status' => $row->kind === 'unclassified' ? 'rejected' : 'approved', 'reason' => 'Explicit synthetic fixture inspection against the original uploaded PDF.'];
            if ($row->kind !== 'unclassified') {
                $payload += ['verify_source' => true, 'resolutions' => $resolutions];
            }
            if ($row->kind === 'ai_visibility_evidence') {
                $payload['data'] = [...$row->data, 'brand_appearance' => 'appears'];
            }
            $this->patchJson($root.'/data/records/'.$row->id, $payload)->assertOk();
        }
        $summary = $this->getJson($root.'/summary')->assertOk()->json('data');
        $sections = array_fill_keys(ReportSummaryController::SECTIONS, 'Synthetic fixture observations checked against the original monthly PDFs; source-period totals only.');
        $this->postJson($root.'/summary', ['revision' => $summary['revision'], 'source_fingerprint' => $summary['source_fingerprint'], 'sections' => $sections, 'reason' => 'Explicit review of synthetic source-backed commentary.', 'confirmed' => true])->assertOk();
        $this->postJson($root.'/delivery/commentary-approval', ['revision' => $summary['revision'] + 1, 'reason' => 'Synthetic commentary checked against the fixture PDFs.', 'confirmed' => true])->assertOk();
        $this->getJson($root.'/summary')->assertOk()->assertJsonPath('data.approved', true);
        $manager = $this->getJson($root.'/delivery')->assertOk()->json('data.readiness.manager_approval');
        $this->assertTrue($manager['can_approve'], json_encode($manager));
        $this->postJson($root.'/delivery/approve-report', ['confirmed' => true, 'version' => $p->fresh()->version, 'fingerprint' => $manager['fingerprint'], 'reason' => 'Manager reviewed the complete synthetic report and source evidence.'])->assertOk();

        return $this->postJson($root.'/delivery/publish', ['version' => $p->fresh()->version, 'confirmed' => true])->assertCreated()->json('data');
    }

    public function test_six_real_pdfs_generate_two_isolated_months_and_a_private_draft_without_approvals(): void
    {
        $aug = $this->period('2026-08');
        $this->upload($aug, '08');
        $this->process($aug);
        $this->generate($aug);
        $a = $this->getJson($this->root($aug).'/dashboard?include_provisional=1')->assertOk()->json('data');
        $metrics = collect($a['kpis'])->keyBy('metric_key');
        $this->assertSame(812, $metrics['ga4.total_users']['value']);
        $this->assertSame(91, $metrics['gsc.clicks']['value']);
        foreach (['report_metrics', 'traffic_channels', 'device_metrics', 'keyword_rankings', 'seo_activities', 'ai_visibility_evidence', 'event_metrics'] as $kind) {
            $this->assertTrue(collect($a['records'])->contains('kind', $kind), $kind.' was not extracted');
        }
        $this->assertSame(0, NormalizedRecord::where('review_status', 'approved')->count());
        $before = NormalizedRecord::where('reporting_period_id', $aug->id)->orderBy('id')->get()->toJson();
        $sep = $this->period('2026-09');
        $this->upload($sep, '09');
        $this->process($sep);
        $this->generate($sep);
        $b = $this->getJson($this->root($sep).'/dashboard?include_provisional=1')->assertOk()->json('data');
        $this->assertSame(937, collect($b['kpis'])->keyBy('metric_key')['ga4.total_users']['value']);
        $this->assertSame($before, NormalizedRecord::where('reporting_period_id', $aug->id)->orderBy('id')->get()->toJson());
        $this->getJson($this->root($aug).'/generation')->assertOk()->assertJsonCount(6, 'data.classifications');
        $e = $this->postJson($this->root($aug).'/delivery/exports', ['type' => 'draft', 'profile' => 'client_preview'])->assertAccepted()->json('data.id');
        (new GenerateReportPdf($e))->handle();
        $this->assertSame('completed', ReportPdfExport::findOrFail($e)->status);
        $this->postJson($this->root($aug).'/delivery/publish', ['version' => 1, 'confirmed' => true])->assertConflict();
        $this->assertDatabaseCount('report_publications', 0);
        $this->getJson('/api/monthly-reports')->assertOk()->assertJsonPath('total', 2);
        Queue::assertPushed(GenerateMonthlyReport::class, 2);

        $pub = $this->publishReviewedSynthetic($aug);
        $this->publishReviewedSynthetic($sep);
        (new GenerateReportPdf($pub['export_id']))->handle();
        Notification::fake();
        $viewerId = $this->postJson('/api/team', ['name' => 'Synthetic client', 'email' => 'monthly-client@example.test', 'role' => 'client_viewer', 'is_active' => true])->assertCreated()->assertJsonPath('password_setup_sent', true)->json('data.id');
        $viewer = User::findOrFail($viewerId);
        $setupToken = null;
        Notification::assertSentTo($viewer, ResetPassword::class, function ($n) use (&$setupToken) {
            $setupToken = $n->token;

            return true;
        });
        $root = $this->root($aug);
        $sepRoot = $this->root($sep);
        $grant = ['user_ids' => [$viewerId], 'expires_at' => now()->addDay()->toIso8601String(), 'allow_download' => false];
        $this->putJson($root.'/shares', $grant)->assertOk();
        $link = $this->postJson($root.'/delivery/shares', ['publication_id' => $pub['id'], 'label' => 'Synthetic login link', 'expires_at' => now()->addDay()->toIso8601String(), 'allow_download' => true])->assertCreated()->json('data');
        $headers = ['X-Report-Token' => explode('#', $link['url'])[1]];
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/shared-report', $headers)->assertUnauthorized();
        $this->postJson('/reset-password', ['email' => $viewer->email, 'token' => $setupToken, 'password' => 'Synthetic-Private9!Password', 'password_confirmation' => 'Synthetic-Private9!Password'])->assertOk();
        $this->postJson('/login', ['email' => $viewer->email, 'password' => 'Synthetic-Private9!Password'])->assertOk()->assertJsonPath('user.role', 'client_viewer');
        $this->getJson('/api/portal/reports')->assertOk()->assertJsonPath('total', 1);
        $this->getJson($root.'/client-report')->assertOk();
        $this->getJson($sepRoot.'/client-report')->assertNotFound();
        $this->getJson($root.'/source-audit')->assertForbidden();
        $this->getJson($sepRoot.'/comparison?with='.$aug->id)->assertNotFound();
        $this->getJson('/api/shared-report', $headers)->assertOk();
        $this->getJson('/api/shared-report?pdf=1', $headers)->assertForbidden();
        $this->getJson($root.'/dashboard')->assertForbidden();
        $this->getJson('/api/monthly-reports')->assertForbidden();
        $this->getJson('/api/team')->assertForbidden();
        $outsider = User::factory()->create(['role' => 'client_viewer', 'is_super_admin' => false]);
        $this->actingAs($outsider)->getJson('/api/shared-report', $headers)->assertNotFound();
        $this->getJson($root.'/client-report')->assertNotFound();
        $this->actingAs($this->admin)->putJson($root.'/shares', [...$grant, 'allow_download' => true])->assertOk();
        $this->putJson($sepRoot.'/shares', $grant)->assertOk();
        $this->actingAs($viewer)->getJson('/api/portal/reports')->assertOk()->assertJsonPath('total', 2);
        $this->getJson($sepRoot.'/client-report')->assertOk();
        $this->getJson($sepRoot.'/comparison?with='.$aug->id)->assertOk()->assertJsonPath('data.metrics.0.comparable', true);
        $this->getJson('/api/shared-report?pdf=1', $headers)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->admin)->postJson($root.'/delivery/shares/'.$link['id'].'/revoke', ['confirmed' => true])->assertOk();
        $this->actingAs($viewer)->getJson('/api/shared-report', $headers)->assertNotFound();
        $this->actingAs($this->admin)->putJson($root.'/shares', ['user_ids' => []])->assertOk();
        $this->actingAs($viewer)->getJson($root.'/client-report')->assertNotFound();
        $this->getJson($sepRoot.'/comparison?with='.$aug->id)->assertNotFound();
        $this->getJson('/api/portal/reports')->assertOk()->assertJsonPath('total', 1);
        $this->travel(2)->days();
        $this->getJson('/api/portal/reports')->assertOk()->assertJsonPath('total', 0);
        $this->travelBack();
    }

    public function test_generation_waits_for_extraction_and_duplicate_dispatch_is_idempotent(): void
    {
        $p = $this->period('2026-08');
        $this->upload($p, '08');
        $id = $this->postJson($this->root($p).'/generation')->assertAccepted()->json('data.id');
        $job = new GenerateMonthlyReport($id);
        $queue = \Mockery::mock(Job::class);
        $queue->shouldReceive('release')->once()->with(5);
        $job->setJob($queue);
        $job->handle(app(ReportNormalization::class), app(DocumentAccess::class));
        $this->assertSame('queued', ReportGeneration::find($id)->status);
        $this->assertDatabaseCount('normalization_runs', 0);
        $this->process($p);
        $job->handle(app(ReportNormalization::class), app(DocumentAccess::class));
        $job->handle(app(ReportNormalization::class), app(DocumentAccess::class));
        $this->assertDatabaseCount('normalization_runs', 1);
    }

    public function test_empty_failed_unauthorized_and_non_draft_generation_are_blocked_and_retry_is_explicit(): void
    {
        $p = $this->period('2026-08');
        $root = $this->root($p);
        $this->postJson($root.'/generation')->assertUnprocessable();
        $this->upload($p, '08');
        ReportDocument::where('reporting_period_id', $p->id)->update(['extraction_status' => 'failed']);
        $id = $this->postJson($root.'/generation')->assertAccepted()->json('data.id');
        try {
            (new GenerateMonthlyReport($id))->handle(app(ReportNormalization::class), app(DocumentAccess::class));
            $this->fail('Expected unreadable source failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No readable source', $e->getMessage());
        }
        $this->getJson($root.'/generation')->assertOk()->assertJsonPath('data.generation.status', 'failed');
        $retry = $this->postJson($root.'/generation')->assertAccepted()->json('data.id');
        $this->assertNotSame($id, $retry);
        $outsider = User::factory()->create(['is_super_admin' => false, 'role' => 'seo_manager']);
        $this->actingAs($outsider)->postJson($root.'/generation')->assertNotFound();
        $this->actingAs($this->admin);
        $p->update(['status' => 'approved']);
        $this->postJson($root.'/generation')->assertForbidden();
    }

    public function test_worker_failure_closes_its_normalization_and_allows_a_new_explicit_attempt(): void
    {
        $p = $this->period('2026-08');
        $this->upload($p, '08');
        $run = NormalizationRun::create(['project_id' => $p->project_id, 'reporting_period_id' => $p->id, 'created_by' => $this->admin->id, 'status' => 'processing', 'fingerprint' => str_repeat('a', 64), 'input_snapshot' => []]);
        $g = ReportGeneration::create(['reporting_period_id' => $p->id, 'requested_by' => $this->admin->id, 'status' => 'processing', 'normalization_run_id' => $run->id]);
        (new GenerateMonthlyReport($g->id))->failed(new \RuntimeException('Synthetic worker interruption'));
        $this->assertSame('failed', $g->fresh()->status);
        $this->assertSame('failed', $run->fresh()->status);
        $next = $this->postJson($this->root($p).'/generation')->assertAccepted()->json('data.id');
        $this->assertNotSame($g->id, $next);
        $this->assertDatabaseCount('report_generations', 2);
        $this->assertDatabaseCount('record_corrections', 0);
        $g->update(['status' => 'completed']);
        $run->update(['status' => 'completed']);
        (new GenerateMonthlyReport($g->id))->failed(new \RuntimeException('Duplicate failure delivery'));
        $this->assertSame('completed', $g->fresh()->status);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_local_invitation_is_retained_in_a_private_mail_log_without_exposing_the_token_in_api(): void
    {
        config(['mail.default' => 'log', 'mail.mailers.log.channel' => 'mail', 'logging.channels.mail.path' => Storage::disk('local')->path('local-invitations.log'), 'logging.channels.single.level' => 'warning']);
        $response = $this->postJson('/api/team', ['name' => 'Local invited client', 'email' => 'local-invitation@example.test', 'role' => 'client_viewer', 'is_active' => true])->assertCreated()->assertJsonPath('invitation_delivery', 'local_log');
        $log = Storage::disk('local')->get('local-invitations.log');
        $this->assertStringContainsString('reset-password', $log);
        $this->assertStringContainsString('local-invitation@example.test', $log);
        $this->assertStringNotContainsString('token=', $response->getContent());
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_stale_generation_and_sources_are_visible_and_can_be_retried_without_resetting_history(): void
    {
        $p = $this->period('2026-08');
        $this->upload($p, '08');
        $g = ReportGeneration::create(['reporting_period_id' => $p->id, 'requested_by' => $this->admin->id, 'updated_at' => now()->subMinutes(36)]);
        foreach ($p->documents as $doc) {
            $doc->currentRun->update(['created_at' => now()->subMinutes(10)]);
        }
        $this->getJson($this->root($p).'/generation')->assertOk()->assertJsonPath('data.generation.stale', true)->assertJsonPath('data.documents.0.stale', true)->assertJsonPath('data.documents.0.can_retry', true);
        $id = $this->postJson($this->root($p).'/generation')->assertAccepted()->json('data.id');
        $this->assertNotSame($g->id, $id);
        $this->assertSame('failed', $g->fresh()->status);
        $this->assertDatabaseCount('report_documents', 6);
        $this->assertDatabaseCount('document_extraction_runs', 6);
    }

    public function test_generated_commentary_is_source_backed_unapproved_idempotent_and_preserves_manual_revisions(): void
    {
        $p = $this->period('2026-08');
        $this->upload($p, '08');
        $this->process($p);
        $this->generate($p);
        $root = $this->root($p);
        $summary = $this->getJson($root.'/summary')->assertOk()->assertJsonPath('data.origin', 'generated')->json('data');
        $this->assertCount(7, $summary['sections']);
        $this->assertStringContainsString('812', $summary['sections']['website_traffic']);
        $this->assertDatabaseCount('report_summary_approvals', 0);
        $this->assertDatabaseCount('record_verifications', 0);
        $payload = ['revision' => $summary['revision'], 'source_fingerprint' => $summary['source_fingerprint'], 'confirmed' => true];
        $this->postJson($root.'/summary/generate', $payload)->assertOk()->assertJsonPath('generation.preserved', true);
        $this->postJson($root.'/summary', [...$payload, 'sections' => array_fill_keys(ReportSummaryController::ALL_SECTIONS, 'Manual observations retained from inspected synthetic sources.'), 'reason' => 'Manager checked the synthetic source evidence.'])->assertOk();
        app(DraftCommentary::class)->generate($p, $this->admin);
        $manual = $this->getJson($root.'/summary')->assertOk()->assertJsonPath('data.origin', 'manual')->json('data');
        $this->assertSame($summary['revision'] + 1, $manual['revision']);
        $this->postJson($root.'/summary/generate', [...$payload, 'revision' => $manual['revision'], 'replace_manual' => true, 'confirmed' => false])->assertUnprocessable();
        $this->postJson($root.'/summary/generate', [...$payload, 'revision' => $manual['revision'], 'replace_manual' => true])->assertOk()->assertJsonPath('data.origin', 'generated')->assertJsonPath('data.revision', $manual['revision'] + 1);
        $this->postJson($root.'/summary/generate', $payload)->assertConflict();
        $this->assertDatabaseCount('report_summary_approvals', 0);
        $this->assertDatabaseCount('report_publications', 0);
    }

    public function test_source_audit_retains_all_representations_and_denies_unassigned_users_and_clients(): void
    {
        $p = $this->period('2026-08');
        $this->upload($p, '08');
        $this->process($p);
        $this->generate($p);
        $root = $this->root($p);
        $audit = $this->getJson($root.'/source-audit')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertCount(6, $audit['documents']);
        $this->assertSame(6, $audit['totals']['uploaded_documents']);
        $this->assertSame(NormalizedRecord::where('reporting_period_id', $p->id)->where('active', true)->count(), $audit['totals']['active_normalized_records']);
        $this->assertFalse($audit['totals']['key_events_source_missing']);
        $this->assertGreaterThan(0, $audit['documents'][0]['units_inspected']);
        $this->actingAs(User::factory()->create(['role' => 'seo_manager']));
        $this->getJson($root.'/source-audit')->assertNotFound();
        $this->getJson($root.'/comparison?with=not-assigned')->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'client_viewer']));
        $this->getJson($root.'/source-audit')->assertNotFound();
        $this->postJson($root.'/summary/generate')->assertNotFound();
    }

    public function test_comparison_uses_same_project_months_and_blocks_missing_coverage(): void
    {
        $a = $this->period('2026-08');
        $b = $this->period('2026-09');
        foreach ([[$a, '08'], [$b, '09']] as [$p,$month]) {
            $this->upload($p, $month);
            $this->process($p);
            $this->generate($p);
        }
        $result = $this->getJson($this->root($b).'/comparison?with='.$a->id)->assertOk()->json('data');
        $users = collect($result['metrics'])->firstWhere('metric_key', 'ga4.total_users');
        $this->assertTrue($users['comparable']);
        $this->assertSame(125, $users['difference']);
        $otherProject = Project::factory()->create(['user_id' => $this->admin->id]);
        $otherId = $this->postJson('/api/projects/'.$otherProject->id.'/reports', ['title' => 'Other project month', 'reporting_month' => '2026-08', 'kind' => 'monthly'])->assertCreated()->json('data.id');
        $this->getJson($this->root($b).'/comparison?with='.$otherId)->assertNotFound();
        $this->getJson($this->root($b).'/comparison?with='.$b->id)->assertUnprocessable();
    }

    public function test_sequential_per_file_uploads_enforce_shared_batch_limits_and_regeneration_preserves_identity(): void
    {
        $p = $this->period('2026-08');
        $root = $this->root($p);
        $batch = (string) Str::uuid();
        for ($i = 0; $i < 10; $i++) {
            $this->post($root.'/documents', ['batch_id' => $batch, 'files' => [UploadedFile::fake()->createWithContent('source-'.$i.'.csv', "Keyword,Ranking\nSynthetic ".$i.',1st Page')]], ['Accept' => 'application/json'])->assertCreated();
        }
        $this->post($root.'/documents', ['batch_id' => $batch, 'files' => [UploadedFile::fake()->createWithContent('extra.csv', "Keyword,Ranking\nExtra,2nd Page")]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('report_documents', 10);
        $this->process($p);
        $this->generate($p);
        $ids = NormalizedRecord::where('reporting_period_id', $p->id)->orderBy('id')->pluck('id')->all();
        $sections = DB::table('report_sections')->where('reporting_period_id', $p->id)->pluck('created_at', 'id')->all();
        $this->travel(1)->minutes();
        $this->generate($p);
        $this->assertSame($ids, NormalizedRecord::where('reporting_period_id', $p->id)->orderBy('id')->pluck('id')->all());
        $this->assertSame($sections, DB::table('report_sections')->where('reporting_period_id', $p->id)->pluck('created_at', 'id')->all());
        $this->travelBack();
        $this->assertDatabaseCount('record_corrections', 0);
        $this->assertDatabaseCount('report_publications', 0);
    }

    public function test_assigned_employees_and_managers_consolidate_separate_clients_and_publish_two_authorized_months(): void
    {
        $a = $this->project;
        $clientB = $this->postJson('/api/clients', ['name' => 'Isolated Client B', 'website' => 'https://client-b.example', 'status' => 'active'])->assertCreated()->json('data.id');
        $b = Project::factory()->create(['user_id' => $this->admin->id, 'client_id' => $clientB]);
        $staff = [];
        foreach (['seo_executive', 'seo_executive', 'seo_manager', 'seo_manager', 'client_viewer', 'client_viewer'] as $role) {
            $staff[] = User::factory()->create(['role' => $role, 'is_super_admin' => false, 'managed_by' => $this->admin->id]);
        }
        [$execA,$execB,$managerA,$managerB,$viewerA,$viewerB] = $staff;
        $a->members()->attach([$execA->id => ['assignment_role' => 'member'], $managerA->id => ['assignment_role' => 'manager']]);
        $b->members()->attach([$execB->id => ['assignment_role' => 'member'], $managerB->id => ['assignment_role' => 'manager']]);
        $this->actingAs($execA);
        $aug = $this->period('2026-08');
        $this->upload($aug, '08');
        $this->project = $b;
        $this->actingAs($execB);
        $bPeriod = $this->period('2026-09');
        $this->upload($bPeriod, '09');
        $this->process($bPeriod);
        $this->generate($bPeriod);
        $bRoot = $this->root($bPeriod);
        $this->project = $a;
        $this->actingAs($execA);
        $this->process($aug);
        $this->generate($aug);
        $aRoot = $this->root($aug);
        $this->getJson($aRoot.'/dashboard?include_provisional=1')->assertOk();
        $this->getJson($bRoot.'/dashboard?include_provisional=1')->assertNotFound();
        $this->postJson($aRoot.'/delivery/publish', ['version' => $aug->fresh()->version, 'confirmed' => true])->assertForbidden();
        $this->patchJson($aRoot.'/status', ['status' => 'needs_review', 'version' => $aug->fresh()->version])->assertOk();
        $this->actingAs($managerB)->getJson($aRoot.'/delivery')->assertNotFound();
        $this->actingAs($managerA)->patchJson($aRoot.'/status', ['status' => 'draft', 'version' => $aug->fresh()->version])->assertOk();
        $pubA = $this->publishReviewedSynthetic($aug);
        $snapshot = ReportPublication::findOrFail($pubA['id'])->snapshot;
        $before = NormalizedRecord::where('reporting_period_id', $aug->id)->orderBy('id')->get()->toJson();
        $linkA = $this->postJson($aRoot.'/delivery/shares', ['publication_id' => $pubA['id'], 'user_id' => $viewerA->id, 'label' => 'Client A monthly report', 'allow_download' => true])->assertCreated()->json('data.url');
        $this->project = $b;
        $this->actingAs($managerB);
        $pubB = $this->publishReviewedSynthetic($bPeriod);
        $linkB = $this->postJson($bRoot.'/delivery/shares', ['publication_id' => $pubB['id'], 'user_id' => $viewerB->id, 'label' => 'Client B monthly report', 'allow_download' => false])->assertCreated()->json('data.url');
        $this->actingAs($viewerA)->getJson('/api/shared-report', ['X-Report-Token' => parse_url($linkA, PHP_URL_FRAGMENT)])->assertOk();
        $this->getJson('/api/shared-report', ['X-Report-Token' => parse_url($linkB, PHP_URL_FRAGMENT)])->assertNotFound();
        $this->getJson($bRoot.'/client-report')->assertNotFound();
        $this->project = $a;
        $this->actingAs($execA);
        $sep = $this->period('2026-09');
        $this->upload($sep, '09');
        $this->process($sep);
        $this->generate($sep);
        $this->assertSame($before, NormalizedRecord::where('reporting_period_id', $aug->id)->orderBy('id')->get()->toJson());
        $this->assertSame($snapshot, ReportPublication::findOrFail($pubA['id'])->snapshot);
        $this->actingAs($managerA);
        $pubSep = $this->publishReviewedSynthetic($sep);
        $this->postJson($this->root($sep).'/delivery/shares', ['publication_id' => $pubSep['id'], 'user_id' => $viewerA->id, 'label' => 'Client A September', 'allow_download' => false])->assertCreated();
        $this->actingAs($viewerA)->getJson('/api/portal/reports')->assertOk()->assertJsonPath('total', 2)->assertDontSee('Isolated Client B');
        $this->getJson($aRoot.'/dashboard')->assertForbidden();
        $this->assertSame($snapshot, ReportPublication::findOrFail($pubA['id'])->snapshot);
    }

    public function test_reset_and_regeneration_preserve_sources_reuse_observation_ids_and_leave_other_months_alone(): void
    {
        $aug = $this->period('2026-08');
        $sep = $this->period('2026-09');
        $this->upload($aug, '08');
        $this->process($aug);
        $this->generate($aug);
        $ids = NormalizedRecord::where('reporting_period_id', $aug->id)->orderBy('id')->pluck('id')->all();
        $docs = $aug->documents()->orderBy('id')->pluck('checksum', 'id')->all();
        $url = '/api/lifecycle/report/'.$aug->id;
        $token = $this->getJson($url)->assertOk()->json('data.preview_token');
        $this->postJson($url, ['action' => 'reset', 'confirmed' => true, 'preview_token' => $token])->assertOk();
        $this->assertSame(0, NormalizedRecord::where('reporting_period_id', $aug->id)->where('active', true)->count());
        $this->assertTrue($aug->fresh()->requires_regeneration);
        $this->generate($aug);
        $this->assertSame($ids, NormalizedRecord::where('reporting_period_id', $aug->id)->orderBy('id')->pluck('id')->all());
        $this->assertSame($docs, $aug->documents()->orderBy('id')->pluck('checksum', 'id')->all());
        $this->assertFalse($aug->fresh()->requires_regeneration);
        $this->assertSame('draft', $sep->fresh()->status);
        $this->assertSame(0, $sep->documents()->count());
    }

    public function test_three_projects_process_real_hanns_copy_and_different_synthetic_formats_without_cross_project_values(): void
    {
        $hanns = $this->period('2026-08');
        $this->project->update(['name' => "Hann's Pharmacy - isolated QA copy"]);
        $references = glob(rtrim(getenv('TEST_REFERENCE_DIR') ?: base_path('../reference-files'), '/').'/*');
        $files = [];
        foreach ($references as $file) {
            if (is_file($file)) {
                $files[] = new UploadedFile($file, basename($file), null, null, true);
            }
        }
        $this->assertCount(5, $files);
        $this->post($this->root($hanns).'/documents', ['files' => $files], ['Accept' => 'application/json'])->assertCreated();
        $this->process($hanns);
        $this->generate($hanns);
        $first = NormalizedRecord::where('reporting_period_id', $hanns->id)->count();
        $this->assertGreaterThan(0, $first);
        foreach (['B', 'C'] as $suffix) {
            $client = $this->postJson('/api/clients', ['name' => 'Synthetic Local '.$suffix, 'status' => 'active'])->assertCreated()->json('data.id');
            $this->project = Project::factory()->create(['client_id' => $client, 'user_id' => $this->admin->id, 'name' => 'Synthetic Project '.$suffix]);
            $p = $this->period($suffix === 'B' ? '2026-09' : '2026-10');
            if ($suffix === 'B') {
                $this->upload($p, '09');
            } else {
                $files = [];
                foreach (['synthetic-scanned.pdf', 'synthetic-screenshot.png', 'synthetic-screenshot.jpg', 'synthetic-keywords.xlsx', 'synthetic-events.csv'] as $name) {
                    $files[] = new UploadedFile(base_path('../local/fixtures/'.$name), $name, null, null, true);
                }
                $this->post($this->root($p).'/documents', ['files' => $files], ['Accept' => 'application/json'])->assertCreated();
            }
            $this->process($p);
            $this->generate($p);
            $rows = NormalizedRecord::where('reporting_period_id', $p->id)->where('active', true)->get();
            $this->assertGreaterThan(0, $rows->count());
            $this->assertTrue($rows->every(fn ($r) => $r->project_id === $p->project_id));
            foreach ($rows as $row) {
                foreach ($row->sources as $source) {
                    $this->assertSame($p->id, ReportDocument::findOrFail($source->document_id)->reporting_period_id);
                }
            }
            if ($suffix === 'C') {
                $scan = $p->documents()->where('original_filename', 'synthetic-scanned.pdf')->firstOrFail();
                $this->assertStringContainsString('1200', $scan->currentRun->raw_json);
                $this->assertTrue($rows->contains(fn ($r) => $r->kind === 'keyword_rankings'));
                $this->assertTrue($rows->contains(fn ($r) => $r->kind === 'event_metrics'));
            }
        }
        $this->assertSame($first, NormalizedRecord::where('reporting_period_id', $hanns->id)->count());
        $this->assertSame('draft', $hanns->fresh()->status);
    }

    public function test_public_monthly_reports_file_automatically_keep_links_and_support_next_month_and_trash(): void
    {
        $aug = $this->period('2026-08');
        $this->upload($aug, '08');
        $this->process($aug);
        $this->generate($aug);
        $root = $this->root($aug);
        $pub = $this->publishReviewedSynthetic($aug);
        $this->getJson('/api/monthly-reports')->assertOk()->assertJsonPath('total', 1);
        $revision = $aug->fresh()->version;
        $snapshot = DB::table('report_publications')->where('id', $pub['id'])->first();
        $request = ['publication_id' => $pub['id'], 'label' => 'Synthetic August public link', 'access_mode' => 'public', 'public_confirmed' => true, 'allow_download' => true];
        $share = $this->postJson($root.'/delivery/shares', $request)->assertCreated()->json('data');
        $token = explode('#', $share['url'])[1];
        $this->assertNotNull($aug->fresh()->workspace_archived_at);
        $this->assertSame('published', $aug->fresh()->status);
        $this->assertSame($revision, $aug->fresh()->version);
        $this->getJson('/api/monthly-reports')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/monthly-reports?tab=history')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.publication_id', $pub['id'])->assertJsonPath('data.0.public_share.can_copy', true)->assertJsonMissingPath('data.0.public_share.encrypted_token');
        $this->getJson($root.'/delivery/shares/'.$share['id'].'/copy')->assertOk()->assertJsonPath('url', $share['url']);
        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->assertSame([], $dashboard['recent_reports']);
        $this->assertSame($aug->id, $dashboard['recent_published_reports'][0]['id']);
        $pdf = ReportPdfExport::where('publication_id', $pub['id'])->firstOrFail();
        (new GenerateReportPdf($pdf->id))->handle();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/public-report', ['X-Report-Token' => $token])->assertOk();
        $this->getJson('/api/public-report?pdf=1', ['X-Report-Token' => $token])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson($root.'/delivery/shares/'.$share['id'].'/copy')->assertUnauthorized();
        $this->actingAs($this->admin);
        $sep = $this->period('2026-09');
        $this->upload($sep, '09');
        $this->process($sep);
        $this->generate($sep);
        $sepPub = $this->publishReviewedSynthetic($sep);
        $sepShare = $this->postJson($this->root($sep).'/delivery/shares', [...$request, 'publication_id' => $sepPub['id'], 'label' => 'Synthetic September public link'])->assertCreated()->json('data');
        $this->assertNotSame($share['url'], $sepShare['url']);
        $sepPdf = ReportPdfExport::where('publication_id', $sepPub['id'])->firstOrFail();
        (new GenerateReportPdf($sepPdf->id))->handle();
        $oct = $this->period('2026-10');
        $this->getJson('/api/monthly-reports')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $oct->id);
        $this->getJson('/api/monthly-reports?tab=history')->assertJsonPath('total', 2);
        $this->assertSame((array) $snapshot, (array) DB::table('report_publications')->where('id', $pub['id'])->first());
        $this->assertSame(6, $aug->documents()->count());
        $this->assertSame(6, $sep->documents()->count());
        $this->assertSame(0, $oct->documents()->count());
        $life = '/api/lifecycle/report/'.$aug->id;
        $preview = $this->getJson($life)->assertOk()->json('data.preview_token');
        $this->postJson($life, ['action' => 'restore_active', 'confirmed' => true, 'preview_token' => $preview])->assertOk();
        $this->assertNull($aug->fresh()->workspace_archived_at);
        $this->assertSame($revision, $aug->fresh()->version);
        $this->getJson('/api/public-report', ['X-Report-Token' => $token])->assertOk();
        $preview = $this->getJson($life)->json('data.preview_token');
        $this->postJson($life, ['action' => 'archive', 'confirmed' => true, 'preview_token' => $preview])->assertOk();
        $this->assertSame('published', $aug->fresh()->status);
        $this->getJson('/api/public-report', ['X-Report-Token' => $token])->assertOk();
        $this->postJson($root.'/delivery/shares/'.$share['id'].'/revoke', ['confirmed' => true])->assertOk();
        $this->getJson('/api/public-report', ['X-Report-Token' => $token])->assertNotFound();
        $this->getJson($root.'/delivery/shares/'.$share['id'].'/copy')->assertNotFound();
        $this->postJson($root.'/delivery/shares', $request)->assertCreated();
        $life = '/api/lifecycle/report/'.$sep->id;
        $preview = $this->getJson($life)->json('data.preview_token');
        $this->postJson($life, ['action' => 'trash', 'confirmed' => true, 'preview_token' => $preview])->assertOk();
        $this->getJson('/api/public-report', ['X-Report-Token' => explode('#', $sepShare['url'])[1]])->assertNotFound();
        $this->getJson('/api/monthly-reports?tab=trash')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $sep->id);
        $this->assertNotNull(ReportingPeriod::find($aug->id));
        $this->assertNotNull(ReportingPeriod::find($oct->id));
        $this->assertSame((array) $snapshot,(array) DB::table('report_publications')->where('id',$pub['id'])->first());
        $other = User::factory()->create(['is_super_admin' => false, 'role' => 'seo_executive']);
        $this->actingAs($other)->getJson('/api/monthly-reports?tab=history')->assertJsonPath('total',0);
        $this->getJson('/api/monthly-reports?tab=trash')->assertForbidden();
        $this->getJson($root.'/delivery/shares/'.$share['id'].'/copy')->assertNotFound();
    }
}
