<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportSummaryController;
use App\Jobs\GenerateReportPdf;
use App\Models\Client;
use App\Models\NormalizationRun;
use App\Models\NormalizedRecord;
use App\Models\Project;
use App\Models\ReportDocument;
use App\Models\ReportingPeriod;
use App\Models\ReportPdfExport;
use App\Models\ReportPublication;
use App\Models\User;
use App\Services\ReportDelivery;
use App\Services\ReportNormalization;
use App\Services\ReportPdfPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReportDeliveryTest extends TestCase
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
        Queue::fake();
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

    private function ready(): string
    {
        $items = [
            ['report_metrics', 'website_traffic', ['metric_key' => 'ga4.total_users', 'label' => 'Total users', 'value' => 4515, 'unit' => 'count', 'definition' => 'ga4']],
            ['report_metrics', 'organic_search', ['metric_key' => 'gsc.clicks', 'label' => 'Clicks', 'value' => 2950, 'unit' => 'count', 'definition' => 'gsc']],
            ['report_metrics', 'organic_search', ['metric_key' => 'gsc.impressions', 'label' => 'Impressions', 'value' => 432736, 'unit' => 'count', 'definition' => 'gsc']],
            ['keyword_rankings', 'keywords', ['keyword' => 'Pharmacy', 'ranking_label' => '1st Page', 'category' => 'primary']],
            ['seo_activities', 'seo_work', ['category' => 'article_submission', 'url' => 'https://example.test/article']],
            ['ai_visibility_evidence', 'ai_visibility', ['query' => 'Pharmacy', 'platform' => 'Search', 'brand_appearance' => 'appears']],
            ['event_metrics', 'key_events', ['event_name' => 'contact_us', 'count' => 10]],
        ];
        foreach ($items as $i => [$kind,$section,$data]) {
            $row = $this->row('record-'.$i);
            $row->update(['kind' => $kind, 'section_key' => $section, 'source_data' => $data, 'review_status' => 'approved']);
            $this->verify($row)->assertOk();
        }
        $url = str_replace('/data', '', $this->base);
        $summary = $this->getJson($url.'/summary')->assertOk()->json('data');
        $sections = array_fill_keys(ReportSummaryController::SECTIONS, 'Reviewed source-supported executive commentary for this reporting period.');
        $this->postJson($url.'/summary', ['revision' => 0, 'source_fingerprint' => $summary['source_fingerprint'], 'sections' => $sections, 'reason' => 'Reviewed all section narratives against source evidence.', 'confirmed' => true])->assertOk();
        $this->postJson($url.'/delivery/commentary-approval', ['revision' => 1, 'reason' => 'All section commentary checked against source evidence.', 'confirmed' => true])->assertOk();
        $this->getJson($url.'/delivery')->assertOk()->assertJsonPath('data.readiness.eligible', true);
        $this->report->update(['status' => 'approved']);

        return $url;
    }

    private function publishReady(): array
    {
        $url = $this->ready();
        $pub = $this->postJson($url.'/delivery/publish', ['version' => 1, 'confirmed' => true])->assertCreated()->json('data');

        return [$url, $pub];
    }

    public function test_ready_publication_is_immutable_and_duplicate_revision_cannot_publish(): void
    {
        [$url,$pub] = $this->publishReady();
        $model = ReportPublication::findOrFail($pub['id']);
        $before = $model->snapshot;
        $this->assertSame(4515, $before['kpis'][0]['value']);
        $this->assertEmpty($before['warnings']);
        $this->assertArrayNotHasKey('issues', $before['records'][0]);
        $this->assertArrayNotHasKey('notes', $before['records'][0]['data']);
        $this->postJson($url.'/delivery/publish', ['version' => 1, 'confirmed' => true])->assertConflict();
        $this->assertDatabaseCount('report_publications', 1);
        $this->assertDatabaseCount('report_pdf_exports', 1);
        NormalizedRecord::where('reporting_period_id', $this->report->id)->first()->update(['manual_override' => ['value' => 99]]);
        $this->assertSame($before, $model->fresh()->snapshot);
        $this->getJson($url.'/delivery/publications/'.$model->id)->assertOk();
        Queue::assertPushed(GenerateReportPdf::class);
    }

    public function test_pending_and_blocking_validation_and_commentary_prevent_publication(): void
    {
        $this->row();
        $url = str_replace('/data', '', $this->base);
        $this->report->update(['status' => 'approved']);
        $this->getJson($url.'/delivery')->assertOk()->assertJsonPath('data.readiness.eligible', false);
        $this->postJson($url.'/delivery/publish', ['version' => 1, 'confirmed' => true])->assertConflict();
        $this->postJson($url.'/delivery/shares', ['publication_id' => 'missing', 'label' => 'Test', 'expires_at' => now()->addDay()->toIso8601String(), 'allow_download' => false])->assertNotFound();
        $this->assertDatabaseCount('report_publications', 0);
        $this->assertDatabaseCount('report_share_links', 0);
    }

    public function test_share_tokens_are_hashed_expire_revoke_and_do_not_leak_to_audit(): void
    {
        [$url,$pub] = $this->publishReady();
        // Token access now also requires an explicit authenticated account grant.
        $this->report->viewers()->attach($this->user->id, ['shared_by' => $this->user->id, 'expires_at' => now()->addDay(), 'allow_download' => true]);
        $share = $this->postJson($url.'/delivery/shares', ['publication_id' => $pub['id'], 'label' => 'Client', 'expires_at' => now()->addDay()->toIso8601String(), 'allow_download' => false])->assertCreated()->json('data');
        $token = explode('#', $share['url'])[1];
        $this->assertSame(64, strlen($token));
        $this->assertDatabaseHas('report_share_links', ['token_hash' => hash('sha256', $token)]);
        $this->assertStringNotContainsString($token, json_encode(DB::table('report_delivery_events')->get()));
        $this->getJson('/api/shared-report', ['X-Report-Token' => $token])->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertJsonPath('allow_download', false)->assertJsonMissingPath('data.records.0.validation_warnings');
        $this->getJson('/api/shared-report?pdf=1', ['X-Report-Token' => $token])->assertForbidden();
        $this->getJson('/api/shared-report', ['X-Report-Token' => str_repeat('a', 64)])->assertNotFound();
        $this->getJson('/api/shared-report?token='.$token)->assertNotFound();
        $this->travel(2)->days();
        $this->getJson('/api/shared-report', ['X-Report-Token' => $token])->assertNotFound();
        $this->travelBack();
        $this->postJson($url.'/delivery/shares/'.$share['id'].'/revoke', ['confirmed' => true])->assertOk();
        $this->getJson('/api/shared-report', ['X-Report-Token' => $token])->assertNotFound();
    }

    public function test_report_losing_readiness_or_archived_blocks_shared_access(): void
    {
        [$url,$pub] = $this->publishReady();
        // Token access now also requires an explicit authenticated account grant.
        $this->report->viewers()->attach($this->user->id, ['shared_by' => $this->user->id, 'expires_at' => now()->addDay(), 'allow_download' => true]);
        $share = $this->postJson($url.'/delivery/shares', ['publication_id' => $pub['id'], 'label' => 'Client', 'expires_at' => now()->addDay()->toIso8601String(), 'allow_download' => true])->assertCreated()->json('data');
        $headers = ['X-Report-Token' => explode('#', $share['url'])[1]];
        $row = NormalizedRecord::where('reporting_period_id', $this->report->id)->first();
        $row->update(['review_status' => 'pending']);
        $this->getJson('/api/shared-report', $headers)->assertNotFound();
        $row->update(['review_status' => 'approved']);
        $this->getJson('/api/shared-report', $headers)->assertOk();
        $this->report->update(['status' => 'draft']);
        $this->getJson('/api/shared-report', $headers)->assertNotFound();
        $this->report->update(['status' => 'archived']);
        $this->getJson('/api/shared-report', $headers)->assertNotFound();
    }

    public function test_client_pdf_cover_uses_actual_dates_without_generic_coverage_panels(): void
    {
        $data = app(\App\Services\ReportDelivery::class)->snapshot($this->report, $this->user, true);
        $data['source_periods'] = [['document'=>'Analytics.pdf','status'=>'partial','ranges'=>[['start'=>'2026-08-01','end'=>'2026-08-30']],'uncovered_dates'=>['2026-08-31']]];
        $html = view('reports.pdf', ['d'=>$data,'sections'=>['overview'],'profile'=>'client_preview'])->render();
        $this->assertStringContainsString('1 Aug 2026 – 30 Aug 2026', $html);
        $this->assertStringNotContainsString('Actual source coverage', $html);
        $this->assertStringNotContainsString('<strong>Source limitations</strong>', $html);
        $data['source_periods'][0]['status'] = 'uncertain';
        $data['source_periods'][0]['ranges'] = [];
        $html = view('reports.pdf', ['d'=>$data,'sections'=>['overview'],'profile'=>'client_preview'])->render();
        $this->assertStringContainsString('Reporting month (source dates unconfirmed)', $html);
    }

    public function test_client_conversion_section_does_not_repeat_traffic_metrics_or_invent_a_total(): void
    {
        $data=app(\App\Services\ReportDelivery::class)->snapshot($this->report,$this->user,true);
        $data['kpis']=[['metric_key'=>'ga4.total_users','label'=>'Total users','value'=>4515,'unit'=>'count'],['metric_key'=>'events.reported_total','label'=>'Reported total','value'=>97,'unit'=>'count']];
        $data['records']=[['id'=>'event','kind'=>'event_metrics','section_key'=>'key_events','data'=>['event_name'=>'contact_us','count'=>37],'sources'=>[],'image'=>null,'provisional'=>true]];
        $html=view('reports.pdf',['d'=>$data,'sections'=>['overview','key_events'],'profile'=>'client_preview'])->render();
        $this->assertStringContainsString('01 &#183; Conversions',$html);
        $this->assertStringNotContainsString('Total users',$html);
        $this->assertStringContainsString('Grand total',$html);
        $this->assertSame(1,substr_count($html,'class="kpi-label">Grand total'));
        $this->assertStringContainsString('class="kpi-value">97',$html);
        array_pop($data['kpis']);
        $html=view('reports.pdf',['d'=>$data,'sections'=>['overview'],'profile'=>'client_preview'])->render();
        $this->assertStringContainsString('class="kpi-value">Not reported',$html);
    }

    public function test_grouped_client_activity_layout_uses_each_reports_categories_and_keeps_every_url(): void
    {
        $data=app(\App\Services\ReportDelivery::class)->snapshot($this->report,$this->user,true);
        $data['summary']=[];
        foreach(['article_submission','press_release'] as $category){
            $data['records']=[['id'=>'activity','kind'=>'seo_activities','section_key'=>'seo_work','data'=>['category'=>$category,'url'=>'https://example.test/'.$category],'sources'=>[],'image'=>null,'provisional'=>true]];
            $html=view('reports.pdf',['d'=>$data,'sections'=>['seo_work'],'profile'=>'client_preview'])->render();
            $this->assertStringContainsString('Total submissions',$html);
            $this->assertStringContainsString('https://example.test/'.$category,$html);
            $this->assertStringContainsString('1 URLs',$html);
            $this->assertStringNotContainsString('Reported SEO activity mix',$html);
            $this->assertStringNotContainsString('Activity observations</caption>',$html);
        }
    }

    public function test_internal_pdf_exports_are_private_and_do_not_change_report_data(): void
    {
        $row = $this->row()->fresh();
        $before = $row->getRawOriginal();
        $url = str_replace('/data', '', $this->base);
        $export = $this->postJson($url.'/delivery/exports', ['type' => 'draft'])->assertAccepted()->json('data');
        $e = ReportPdfExport::findOrFail($export['id']);
        $this->assertTrue($e->payload['draft']);
        $this->assertTrue($e->payload['records'][0]['provisional']);
        $this->assertSame($before, $row->fresh()->getRawOriginal());
        $this->assertSame('draft', $this->report->fresh()->status);
        $this->assertSame('queued', $e->status);
        $this->getJson($url.'/delivery/exports/'.$e->id.'/download')->assertNotFound();
        $outsider = User::factory()->create(['role' => 'seo_manager', 'is_super_admin' => false]);
        $this->actingAs($outsider)->getJson($url.'/delivery')->assertNotFound();
        $this->postJson($url.'/delivery/exports', ['type' => 'draft'])->assertNotFound();
        $this->getJson($url.'/delivery/exports/'.$e->id.'/download')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson($url.'/delivery')->assertUnauthorized();
    }

    public function test_account_grants_require_expiration_and_cannot_access_other_reports(): void
    {
        [$url,$pub] = $this->publishReady();
        $viewer = User::factory()->create(['role' => 'client_viewer', 'is_super_admin' => false]);
        $this->putJson($url.'/shares', ['user_ids' => [$viewer->id]])->assertConflict();
        $this->putJson($url.'/shares', ['user_ids' => [$viewer->id], 'expires_at' => now()->addDay()->toIso8601String(), 'allow_download' => false])->assertOk();
        $this->actingAs($viewer)->getJson($url.'/client-report')->assertOk();
        $this->getJson($url.'/client-report?pdf=1')->assertForbidden();
        $this->getJson($url.'/delivery')->assertForbidden();
        $this->postJson($url.'/delivery/exports', ['type' => 'draft'])->assertForbidden();
        $this->travel(2)->days();
        $this->getJson($url.'/client-report')->assertNotFound();
        $this->travelBack();
        $this->actingAs($this->user)->putJson($url.'/shares', ['user_ids' => []])->assertOk();
        $this->actingAs($viewer)->getJson($url.'/client-report')->assertNotFound();
    }

    public function test_export_failure_retry_and_retention_preserve_publications(): void
    {
        [$url,$pub] = $this->publishReady();
        $e = ReportPdfExport::findOrFail($pub['export_id']);
        (new GenerateReportPdf($e->id))->failed(new \RuntimeException('Secret error must not escape'));
        $this->assertSame('failed', $e->fresh()->status);
        $this->assertStringNotContainsString('Secret', $e->fresh()->error);
        $this->postJson($url.'/delivery/exports/'.$e->id.'/retry')->assertOk();
        $this->postJson($url.'/delivery/exports/'.$e->id.'/retry')->assertConflict();
        $e->update(['status' => 'completed', 'created_at' => now()->subDays(60)]);
        $draft = ReportPdfExport::create(['reporting_period_id' => $this->report->id, 'generated_by' => $this->user->id, 'type' => 'draft', 'status' => 'completed', 'payload' => [], 'sections' => [], 'created_at' => now()->subDays(60)]);
        $this->artisan('reports:cleanup-exports')->assertSuccessful();
        $this->assertSame('completed', $e->fresh()->status);
        $this->assertSame('expired', $draft->fresh()->status);
        $this->assertDatabaseCount('report_publications', 1);
    }

    public function test_shared_endpoint_rate_limits_invalid_tokens(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/shared-report')->assertNotFound();
        }
        $this->getJson('/api/shared-report')->assertStatus(429);
    }

    public function test_later_publications_preserve_history_and_old_snapshot_bytes(): void
    {
        [$url,$first] = $this->publishReady();
        $one = ReportPublication::findOrFail($first['id']);
        $snapshot = $one->snapshot;
        ReportPdfExport::where('publication_id',$one->id)->where('status','queued')->update(['status'=>'failed']);
        foreach (['archived', 'draft', 'needs_review', 'approved'] as $status) {
            $this->patchJson($url.'/status', ['status' => $status, 'version' => $this->report->fresh()->version])->assertOk();
        }
        $second = $this->postJson($url.'/delivery/publish', ['confirmed' => true, 'version' => $this->report->fresh()->version])->assertCreated()->json('data');
        $this->assertSame(2, $second['version']);
        $this->assertSame($snapshot, $one->fresh()->snapshot);
        $this->assertDatabaseCount('report_publications', 2);
        $this->getJson($url.'/delivery')->assertOk()->assertJsonCount(2, 'data.publications');
    }

    public function test_actual_draft_and_published_pdf_rendering_and_download_permissions(): void
    {
        [$url,$pub] = $this->publishReady();
        // Token access now also requires an explicit authenticated account grant.
        $this->report->viewers()->attach($this->user->id, ['shared_by' => $this->user->id, 'expires_at' => now()->addDay(), 'allow_download' => true]);
        $e = ReportPdfExport::findOrFail($pub['export_id']);
        $html = view('reports.pdf', ['d' => $e->payload, 'sections' => $e->sections])->render();
        foreach (['Conversion events', 'Website traffic & analytics', 'Organic search performance', 'Keyword rankings', 'Off-page backlink activity', 'AI visibility evidence', '4,515'] as $text) {
            $this->assertStringContainsString(e($text), $html);
        }
        $this->assertStringNotContainsString('<h2>Report summary</h2>', $html);
        $this->assertStringNotContainsString('<h2>Sources &amp; reporting notes</h2>', $html);
        $this->assertStringNotContainsString('Internal Review', $html);
        (new GenerateReportPdf($e->id))->handle();
        $e->refresh();
        $this->assertSame('completed', $e->status);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($e->storage_path));
        $this->getJson($url.'/delivery/exports/'.$e->id.'/download')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $share = $this->postJson($url.'/delivery/shares', ['publication_id' => $pub['id'], 'label' => 'PDF allowed', 'expires_at' => now()->addDay()->toIso8601String(), 'allow_download' => true])->assertCreated()->json('data');
        $this->getJson('/api/shared-report?pdf=1', ['X-Report-Token' => explode('#', $share['url'])[1]])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $draft = $this->postJson($url.'/delivery/exports', ['type' => 'draft'])->assertAccepted()->json('data');
        $d = ReportPdfExport::findOrFail($draft['id']);
        $html = view('reports.pdf', ['d' => $d->payload, 'sections' => $d->sections])->render();
        $this->assertStringContainsString('DRAFT', $html);
        $this->assertStringContainsString('Not for Client Distribution', $html);
        (new GenerateReportPdf($d->id))->handle();
        $this->assertSame('completed', $d->fresh()->status);
        // Duplicate job delivery preserves the complete artifact and checksum.
        $hash = $d->fresh()->checksum;
        (new GenerateReportPdf($d->id))->handle();
        $this->assertSame($hash, $d->fresh()->checksum);
    }

    public function test_two_simultaneous_publishers_create_exactly_one_version(): void
    {
        $this->ready();
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'delivery-race-'.Str::uuid().'.sqlite';
        touch($file);
        $pdo = new \PDO('sqlite:'.$file);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys=OFF');
        // Establish WAL before the two child connections race to publish.
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $schema = DB::select("SELECT name,type,sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END");
        try {
            foreach ($schema as $item) {
                $pdo->exec($item->sql);
                if ($item->type !== 'table') {
                    continue;
                }
                foreach (DB::table($item->name)->get() as $row) {
                    $data = (array) $row;
                    $cols = implode(',', array_map(fn ($c) => '"'.$c.'"', array_keys($data)));
                    $stmt = $pdo->prepare('INSERT INTO "'.$item->name.'" ('.$cols.') VALUES ('.implode(',', array_fill(0, count($data), '?')).')');
                    $stmt->execute(array_values($data));
                }
            }
            $start = (string) (microtime(true) + 1);
            $args = [PHP_BINARY, base_path('tests/fixtures/publish-concurrently.php'), $file, $this->report->id, (string) $this->user->id, Storage::disk('local')->path(''), $start];
            $one = new Process($args);
            $two = new Process($args);
            $one->start();
            $two->start();
            $one->wait();
            $two->wait();
            $this->assertTrue($one->isSuccessful(), $one->getErrorOutput());
            $this->assertTrue($two->isSuccessful(), $two->getErrorOutput());
            $statuses = [trim($one->getOutput()), trim($two->getOutput())];
            sort($statuses);
            $this->assertSame(['201', '409'], $statuses);
            $this->assertSame(1, (int) $pdo->query('SELECT count(*) FROM report_publications')->fetchColumn());
        } finally {
            $stmt = null;
            $pdo = null;
            foreach ([$file, $file.'-wal', $file.'-shm'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_confirmation_and_manager_permission_are_required_and_cross_report_ids_are_rejected(): void
    {
        $url = $this->ready();
        $this->postJson($url.'/delivery/publish', ['version' => 1])->assertUnprocessable();
        $executive = User::factory()->create(['role' => 'seo_executive', 'is_super_admin' => false]);
        $this->project->members()->attach($executive->id, ['assignment_role' => 'member']);
        $this->actingAs($executive)->postJson($url.'/delivery/publish', ['version' => 1, 'confirmed' => true])->assertForbidden();
        $this->postJson($url.'/delivery/shares', [])->assertForbidden();
        $this->actingAs($this->user)->getJson($url.'/delivery/publications/other-report-publication')->assertNotFound();
        $this->assertDatabaseCount('report_publications', 0);
    }

    public function test_new_blocking_warning_prevents_publication_and_invalidates_verification(): void
    {
        $url = $this->ready();
        DB::table('validation_warnings')->insert(['id' => (string) Str::ulid(), 'normalization_run_id' => $this->run->id, 'document_id' => $this->doc->id, 'code' => 'uncertain_ocr', 'severity' => 'warning', 'message' => 'Compare OCR against the original source.', 'source' => '{}']);
        $this->getJson($url.'/delivery')->assertOk()->assertJsonPath('data.readiness.eligible', false)->assertJsonCount(1, 'data.readiness.blocking_warnings');
        $this->postJson($url.'/delivery/publish', ['confirmed' => true, 'version' => 1])->assertConflict();
        $this->assertDatabaseCount('report_publications', 0);
    }

    public function test_draft_display_deduplicates_observations_and_preserves_full_private_audit_context(): void
    {
        $url = $this->ready();
        $this->report->update(['status' => 'draft']);
        foreach (['duplicate-one', 'duplicate-two'] as $key) {
            $r = $this->row($key);
            $r->update(['kind' => 'seo_activities', 'section_key' => 'seo_activities', 'source_data' => ['category' => 'article_submission', 'url' => 'https://example.test/same?value=a&other=b']]);
        }
        $historical = $this->row('historical')->fresh();
        $historical->update(['active' => false, 'review_status' => 'rejected']);
        $before = DB::table('normalized_records')->orderBy('id')->get()->toJson();
        $preview = $this->postJson($url.'/delivery/exports', ['type' => 'draft', 'profile' => 'client_preview'])->assertAccepted()->json('data');
        $preview = ReportPdfExport::findOrFail($preview['id']);
        $this->assertArrayNotHasKey('_audit', $preview->payload);
        $view = app(ReportPdfPresentation::class)->build($preview->payload, $preview->sections, 'client_preview');
        $this->assertSame(0, $view['duplicate_observations']);
        $this->assertSame(2, $view['activity_total']);
        $html = view('reports.pdf', ['d' => $preview->payload, 'sections' => $preview->sections, 'profile' => $preview->profile])->render();
        $this->assertStringNotContainsString('Suspected duplicate — same reported URL', $html);
        $this->assertStringNotContainsString($historical->id, $html);
        $audit = $this->postJson($url.'/delivery/exports', ['type' => 'draft', 'profile' => 'internal_audit', 'sections' => ['overview']])->assertAccepted()->json('data');
        $audit = ReportPdfExport::findOrFail($audit['id']);
        $this->assertSame(ReportDelivery::SECTIONS, $audit->sections);
        $this->assertCount(10, $audit->payload['_audit']['registry']);
        $this->assertCount(7, $audit->payload['_audit']['verifications']);
        $html = view('reports.pdf', ['d' => $audit->payload, 'sections' => $audit->sections, 'profile' => $audit->profile])->render();
        $this->assertStringContainsString($historical->id, $html);
        $this->assertStringContainsString('Inactive / historical', $html);
        $this->assertStringContainsString('Record Registry and Review History', $html);
        $this->assertSame($before, DB::table('normalized_records')->orderBy('id')->get()->toJson());
        $this->postJson($url.'/delivery/exports', ['type' => 'draft', 'profile' => 'unrecognized'])->assertUnprocessable();
    }

    public function test_commentary_labels_distinguish_approved_pending_missing_and_outdated(): void
    {
        $p = app(ReportDelivery::class);
        $this->assertSame('missing', $p->snapshot($this->report, $this->user, true)['summary_state']);
        $this->ready();
        $this->report->update(['status' => 'draft']);
        $this->assertSame('approved', $p->snapshot($this->report, $this->user, true)['summary_state']);
        DB::table('report_summary_approvals')->delete();
        $data = $p->snapshot($this->report, $this->user, true);
        $this->assertSame('pending_approval', $data['summary_state']);
        $html = view('reports.pdf', ['d' => $data, 'sections' => ['website_traffic']])->render();
        $this->assertStringContainsString('Commentary: pending approval', $html);
        NormalizedRecord::where('reporting_period_id', $this->report->id)->first()->increment('revision');
        $this->assertSame('outdated', $p->snapshot($this->report, $this->user, true)['summary_state']);
    }

    public function test_audit_profile_cannot_enter_published_or_shared_exports(): void
    {
        [$url,$pub] = $this->publishReady();
        $this->postJson($url.'/delivery/exports', ['type' => 'published', 'publication_id' => $pub['id'], 'profile' => 'internal_audit'])->assertUnprocessable();
        $published = ReportPdfExport::findOrFail($pub['export_id']);
        $this->assertSame('client_preview', $published->profile);
        $this->assertArrayNotHasKey('_audit', $published->payload);
        $viewer = User::factory()->create(['role' => 'client_viewer', 'is_super_admin' => false]);
        $this->putJson($url.'/shares', ['user_ids' => [$viewer->id], 'expires_at' => now()->addDay()->toIso8601String()])->assertOk();
        $this->actingAs($viewer)->postJson($url.'/delivery/exports', ['type' => 'draft', 'profile' => 'internal_audit'])->assertForbidden();
    }

    public function test_pdf_urls_are_escaped_and_never_turned_into_source_markup(): void
    {
        $html = (string) app(ReportPdfPresentation::class)->url('https://example.test/<script>alert(1)</script>?a=1&b=2');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<wbr>', $html);
    }

    public function test_real_renderer_failure_retry_keeps_the_frozen_audit_profile_and_data(): void
    {
        $row = $this->row()->fresh();
        $url = str_replace('/data', '', $this->base);
        $result = $this->postJson($url.'/delivery/exports', ['type' => 'draft', 'profile' => 'internal_audit'])->assertAccepted()->json('data');
        $export = ReportPdfExport::findOrFail($result['id']);
        $payload = $export->payload;
        $node = config('delivery.node');
        config(['delivery.node' => base_path('nonexistent-pdf-renderer-binary')]);
        try {
            (new GenerateReportPdf($export->id))->handle();
            $this->fail('Expected renderer failure.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('PDF rendering failed', $error->getMessage());
        } finally {
            config(['delivery.node' => $node]);
        }
        $this->assertSame('failed', $export->fresh()->status);
        $row->update(['manual_override' => ['count' => 99]]);
        $this->postJson($url.'/delivery/exports/'.$export->id.'/retry')->assertOk();
        $this->assertSame('internal_audit', $export->fresh()->profile);
        $this->assertSame($payload, $export->fresh()->payload);
        (new GenerateReportPdf($export->id))->handle();
        $this->assertSame('completed', $export->fresh()->status);
        $this->assertSame(10, $export->fresh()->payload['records'][0]['data']['count']);
        $this->assertSame($payload, $export->fresh()->payload);
    }
    public function test_selected_client_sharing_is_atomic_preserves_other_recipients_and_supports_revocation(): void
    {
        [$url, $pub] = $this->publishReady();
        $viewer = User::factory()->create(['role' => 'client_viewer', 'is_super_admin' => false]);
        $other = User::factory()->create(['role' => 'client_viewer', 'is_super_admin' => false]);
        $grant = ['shared_by' => $this->user->id, 'expires_at' => now()->addDays(4), 'allow_download' => false];
        $this->report->viewers()->attach($other->id, $grant);
        $request = ['publication_id' => $pub['id'], 'user_id' => $viewer->id, 'label' => 'Selected client', 'allow_download' => true];
        $link = $this->postJson($url.'/delivery/shares', $request)->assertCreated()->json('data');
        $this->assertTrue(now()->addDays(29)->lt(\Carbon\Carbon::parse($link['expires_at'])));
        $this->assertTrue(now()->addDays(31)->gt(\Carbon\Carbon::parse($link['expires_at'])));
        $this->assertCount(2, $this->report->viewers()->get());
        $this->assertFalse((bool) $this->report->viewers()->findOrFail($other->id)->pivot->allow_download);
        $this->assertTrue((bool) $this->report->viewers()->findOrFail($viewer->id)->pivot->allow_download);
        $this->getJson($url.'/delivery')->assertOk()->assertJsonCount(2, 'data.viewers')->assertJsonCount(2, 'data.available_viewers');
        $token = parse_url($link['url'], PHP_URL_FRAGMENT);
        $this->actingAs($viewer)->getJson('/api/shared-report', ['X-Report-Token' => $token])->assertOk()->assertJsonPath('allow_download', true);
        $this->actingAs($this->user)->postJson($url.'/delivery/viewers/'.$viewer->id.'/revoke', ['confirmed' => true])->assertOk();
        $this->actingAs($viewer)->getJson('/api/shared-report', ['X-Report-Token' => $token])->assertNotFound();
        $this->assertTrue($this->report->viewers()->where('users.id', $other->id)->exists());
        $this->actingAs($this->user);
        $inactive = User::factory()->create(['role' => 'client_viewer', 'is_active' => false]);
        $this->postJson($url.'/delivery/shares', [...$request, 'user_id' => $inactive->id])->assertUnprocessable();
        $this->assertDatabaseCount('report_share_links', 1);
        $this->postJson($url.'/delivery/shares', [...$request, 'expires_at' => now()->subDay()->toIso8601String()])->assertUnprocessable();
        $this->actingAs($other)->postJson($url.'/delivery/viewers/'.$other->id.'/revoke', ['confirmed' => true])->assertForbidden();
    }

    public function test_visual_review_is_source_bound_audited_and_never_approves_records_or_publishes(): void
    {
        $url=$this->ready();
        $export=$this->postJson($url.'/delivery/exports',['type'=>'draft','profile'=>'client_preview'])->assertAccepted()->json('data.id');
        (new GenerateReportPdf($export))->handle();
        $d=$this->getJson($url.'/delivery')->assertOk()->json('data');
        $this->assertContains($export,array_column($d['checklist']['reviewable_pdfs'],'id'));
        $request=['confirmed'=>true,'version'=>$d['readiness']['report_revision'],'source_fingerprint'=>$d['checklist']['source_fingerprint'],'summary_revision'=>$d['checklist']['summary_revision'],'dashboard_reviewed'=>true,'pdf_export_id'=>$export];
        $before=NormalizedRecord::orderBy('id')->get()->toJson();
        $this->postJson($url.'/delivery/review-checks',$request)->assertOk();
        $checks=collect($this->getJson($url.'/delivery')->json('data.checklist.items'))->keyBy('key');
        $this->assertTrue($checks['dashboard']['complete']);$this->assertTrue($checks['pdf']['complete']);
        $this->assertSame($before,NormalizedRecord::orderBy('id')->get()->toJson());$this->assertDatabaseCount('report_publications',0);
        $row=NormalizedRecord::where('kind','report_metrics')->firstOrFail();$row->update(['manual_override'=>['value'=>999]]);
        $this->postJson($url.'/delivery/review-checks',$request)->assertConflict();
        $checks=collect($this->getJson($url.'/delivery')->json('data.checklist.items'))->keyBy('key');$this->assertFalse($checks['dashboard']['complete']);$this->assertFalse($checks['pdf']['complete']);
        $executive=User::factory()->create(['role'=>'seo_executive','is_super_admin'=>false]);$this->project->members()->attach($executive->id,['assignment_role'=>'member']);
        $this->actingAs($executive)->postJson($url.'/delivery/review-checks',$request)->assertForbidden();
    }

    public function test_public_links_require_explicit_consent_expose_only_eligible_publication_and_are_revoked_on_trash():void
    {
        [$url,$pub]=$this->publishReady();
        $body=['publication_id'=>$pub['id'],'label'=>'Synthetic public test','access_mode'=>'public','allow_download'=>false];
        $this->postJson($url.'/delivery/shares',$body)->assertUnprocessable();
        $link=$this->postJson($url.'/delivery/shares',[...$body,'public_confirmed'=>true])->assertCreated()->json('data');
        $this->getJson($url.'/delivery')->assertOk()->assertJsonPath('data.checklist.items.7.complete',true);
        $this->assertStringContainsString('/public-report#',$link['url']);$token=explode('#',$link['url'])[1];
        auth()->forgetGuards();$this->app['auth']->forgetGuards();
        $this->getJson('/api/public-report',['X-Report-Token'=>$token])->assertOk()->assertJsonPath('allow_download',false)->assertJsonMissingPath('data.records.0.sources.0.storage_path');
        $this->getJson('/api/public-report?pdf=1',['X-Report-Token'=>$token])->assertForbidden();
        $this->getJson('/api/public-report',['X-Report-Token'=>str_repeat('0',64)])->assertNotFound();
        ReportPdfExport::where('reporting_period_id',$this->report->id)->where('status','queued')->update(['status'=>'failed']);
        $this->actingAs($this->user);$p='/api/lifecycle/report/'.$this->report->id;$preview=$this->getJson($p)->assertOk()->json('data.preview_token');
        $this->postJson($p,['action'=>'trash','confirmed'=>true,'preview_token'=>$preview])->assertOk();
        $this->getJson('/api/public-report',['X-Report-Token'=>$token])->assertNotFound();$this->assertSame(hash('sha256',json_encode(ReportPublication::findOrFail($pub['id'])->snapshot)),ReportPublication::findOrFail($pub['id'])->snapshot_hash);
        $this->travel(31)->days();$token=$this->getJson($p)->assertOk()->json('data.preview_token');$this->postJson($p,['action'=>'purge','confirmed'=>true,'preview_token'=>$token,'confirmation'=>'DELETE '.$this->report->id])->assertOk();
        $this->assertDatabaseMissing('reporting_periods',['id'=>$this->report->id]);$this->assertDatabaseHas('retained_lifecycle_events',['entity_id'=>$this->report->id,'action'=>'retained.report_delivery_events']);
    }

    public function test_legacy_hash_only_public_urls_survive_workspace_filing_and_cannot_be_exposed_by_copy_api():void
    {
        [$url,$pub]=$this->publishReady();
        $share=$this->postJson($url.'/delivery/shares',['publication_id'=>$pub['id'],'label'=>'Legacy public URL','access_mode'=>'public','public_confirmed'=>true,'allow_download'=>false])->assertCreated()->json('data');
        $token=explode('#',$share['url'])[1];$link=DB::table('report_share_links')->where('id',$share['id'])->first();
        $this->assertNotSame($token,$link->encrypted_token);$this->assertStringNotContainsString($token,json_encode(DB::table('report_delivery_events')->get()));
        DB::table('report_share_links')->where('id',$share['id'])->update(['encrypted_token'=>null]);
        $this->getJson($url.'/delivery/shares/'.$share['id'].'/copy')->assertConflict();$this->app['auth']->forgetGuards();
        $this->getJson('/api/public-report',['X-Report-Token'=>$token])->assertOk();$this->getJson('/api/public-report?pdf=1',['X-Report-Token'=>$token])->assertForbidden();
        $this->assertSame('published',$this->report->fresh()->status);$this->assertNotNull($this->report->fresh()->workspace_archived_at);
        $this->travel(31)->days();$this->getJson('/api/public-report',['X-Report-Token'=>$token])->assertNotFound();
    }

}
