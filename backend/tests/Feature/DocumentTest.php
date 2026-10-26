<?php

namespace Tests\Feature;

use App\Jobs\ProcessReportDocument;
use App\Models\Client;
use App\Models\DocumentExtractionRun;
use App\Models\Project;
use App\Models\ReportDocument;
use App\Models\ReportingPeriod;
use App\Models\User;
use App\Services\PythonDocumentProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private ReportingPeriod $period;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->owner = User::factory()->create(['role' => 'super_admin', 'is_super_admin' => true]);
        $client = new Client(['name' => 'Ingestion test', 'status' => 'active']);
        $client->forceFill(['user_id' => $this->owner->id, 'identity_key' => hash('sha256', 'ingestion')])->save();
        $this->project = Project::factory()->create(['user_id' => $this->owner->id, 'client_id' => $client->id]);
        $this->period = $this->project->reports()->create(['title' => 'August 2026', 'reporting_month' => '2026-08', 'kind' => 'monthly', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'draft', 'created_by' => $this->owner->id, 'updated_by' => $this->owner->id]);
        $this->url = '/api/projects/'.$this->project->id.'/reports/'.$this->period->id.'/documents';
        $this->actingAs($this->owner);
    }

    private function csv(string $name = 'report.csv', string $data = "Keyword,Rank\nPharmacy,03\n"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $data);
    }

    private function upload(array $files)
    {
        return $this->post($this->url, ['files' => $files], ['Accept' => 'application/json']);
    }

    private function one(): ReportDocument
    {
        $id = $this->upload([$this->csv()])->assertCreated()->json('data.0.id');

        return ReportDocument::findOrFail($id);
    }

    private function reference(string $term): UploadedFile
    {
        $paths = glob(rtrim(getenv('TEST_REFERENCE_DIR') ?: base_path('../reference-files'), '/').'/*');
        $path = collect($paths)->first(fn ($p) => str_contains(strtolower(basename($p)), strtolower($term)));
        $this->assertNotNull($path);

        return new UploadedFile($path, basename($path), null, null, true);
    }

    public function test_real_pdf_and_five_file_batch_are_private_correctly_associated_and_queued(): void
    {
        $before = $this->period->fresh()->getRawOriginal();
        $files = [];
        foreach (glob(rtrim(getenv('TEST_REFERENCE_DIR') ?: base_path('../reference-files'), '/').'/*') as $p) {
            if (is_file($p)) {
                $files[] = new UploadedFile($p, basename($p), null, null, true);
            }
        }
        $response = $this->upload($files)->assertCreated();
        $this->assertCount(5, $response->json('data'));
        Queue::assertPushed(ProcessReportDocument::class, 5);
        foreach ($response->json('data') as $d) {
            $this->assertSame($this->period->id, $d['reporting_period_id']);
            $this->assertSame($this->project->id, $d['project_id']);
            $this->assertSame('queued', $d['extraction_status']);
            $this->assertSame('uploaded', $d['upload_status']);
            $this->assertArrayNotHasKey('storage_path', $d);
            Storage::disk('local')->assertExists(ReportDocument::find($d['id'])->storage_path);
            $this->get('/api/documents/'.$d['id'].'/download')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        }
        $after=$this->period->fresh()->getRawOriginal();$this->assertSame($before['version']+1,$after['version']);$this->assertSame(1,$after['requires_regeneration']);
        foreach(['version','requires_regeneration','updated_at'] as $key){unset($before[$key],$after[$key]);}$this->assertSame($before,$after);
    }

    public function test_docx_xlsx_csv_and_image_content_validation(): void
    {
        foreach (['docx', 'xlsx'] as $ext) {
            $path = tempnam(sys_get_temp_dir(), 'office');
            $z = new \ZipArchive;
            $z->open($path, \ZipArchive::OVERWRITE);
            $z->addFromString('[Content_Types].xml', '<Types/>');
            $z->addFromString($ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml', '<root/>');
            $z->close();
            $file = UploadedFile::fake()->createWithContent('source.'.$ext, file_get_contents($path));
            unlink($path);
            $this->upload([$file])->assertCreated();
        }
        $this->upload([$this->csv(), $this->reference('key events')])->assertCreated();
    }

    public function test_spoofed_executable_unsafe_archive_size_and_extension_rejected_atomically(): void
    {
        foreach ([['bad.pdf', 'MZ fake'], ['run.exe', 'MZ'], ['bad.csv', '<?php echo 1;'], ['bad.png', 'not an image']] as [$name,$body]) {
            $this->upload([$this->csv(), UploadedFile::fake()->createWithContent($name, $body)])->assertUnprocessable();
        }
        $path = tempnam(sys_get_temp_dir(), 'unsafe');
        $z = new \ZipArchive;
        $z->open($path, \ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', 'x');
        $z->addFromString('word/document.xml', 'x');
        $z->addFromString('../run.exe', 'MZ');
        $z->close();
        $this->upload([UploadedFile::fake()->createWithContent('bad.docx', file_get_contents($path))])->assertUnprocessable();
        unlink($path);
        config(['documents.max_file_mb' => 1]);
        $this->upload([UploadedFile::fake()->create('large.pdf', 2048)])->assertUnprocessable();
        $this->assertDatabaseCount('report_documents', 0);
        Queue::assertNothingPushed();
    }

    public function test_duplicate_checksum_scoped_to_report_and_removed_original_can_be_reuploaded(): void
    {
        $d = $this->one();
        $this->upload([$this->csv()])->assertUnprocessable();
        $this->upload([$this->csv('another.csv', 'a,b'), $this->csv('same.csv', 'a,b')])->assertUnprocessable();
        $d->currentRun->update(['status'=>'failed']);
        $preview=$this->getJson('/api/lifecycle/document/'.$d->id)->assertOk()->json('data.preview_token');
        $this->deleteJson('/api/documents/'.$d->id,['confirmed'=>true,'preview_token'=>$preview])->assertOk();
        $this->assertSoftDeleted('report_documents', ['id' => $d->id]);
        Storage::disk('local')->assertExists($d->storage_path);
        $this->getJson('/api/documents/'.$d->id)->assertNotFound();
        $this->upload([$this->csv()])->assertCreated();
        $this->assertDatabaseHas('retained_lifecycle_events', ['action' => 'trash', 'entity_id' => $d->id]);
    }

    public function test_foreign_ids_download_assets_retry_remove_and_viewer_are_denied(): void
    {
        $d = $this->one();
        $foreign = User::factory()->create(['role' => 'seo_executive', 'is_super_admin' => false]);
        $this->actingAs($foreign);
        foreach (['', '/status', '/download', '/preview', '/extraction', '/assets', '/raw'] as $suffix) {
            $this->getJson('/api/documents/'.$d->id.$suffix)->assertNotFound();
        }
        $this->postJson('/api/documents/'.$d->id.'/retry')->assertNotFound();
        $this->deleteJson('/api/documents/'.$d->id)->assertNotFound();
        $this->upload([$this->csv('foreign.csv', 'x,y')])->assertNotFound();
        $viewer = User::factory()->create(['role' => 'client_viewer', 'is_super_admin' => false]);
        $this->period->update(['status' => 'published']);
        $this->period->viewers()->attach($viewer->id, ['shared_by' => $this->owner->id, 'expires_at' => now()->addDay()]);
        $this->actingAs($viewer)->getJson('/api/documents/'.$d->id)->assertForbidden();
    }

    public function test_report_association_is_server_owned_and_non_draft_uploads_rejected(): void
    {
        $other = Project::factory()->create(['user_id' => $this->owner->id]);
        $this->post('/api/projects/'.$other->id.'/reports/'.$this->period->id.'/documents', ['files' => [$this->csv()]], ['Accept' => 'application/json'])->assertNotFound();
        $d = $this->post($this->url, ['files' => [$this->csv()], 'project_id' => $other->id, 'reporting_period_id' => 'fake'], ['Accept' => 'application/json'])->assertCreated()->json('data.0');
        $this->assertSame($this->project->id, $d['project_id']);
        $this->period->update(['status' => 'approved']);
        $this->upload([$this->csv('second.csv', 'x,y')])->assertForbidden();
        $this->deleteJson('/api/documents/'.$d['id'])->assertForbidden();
    }

    public function test_retry_requires_terminal_or_stale_attempt_and_keeps_previous_runs(): void
    {
        $d = $this->one();
        $old = $d->current_run_id;
        $this->postJson('/api/documents/'.$d->id.'/retry')->assertConflict();
        $d->currentRun()->update(['created_at' => now()->subMinutes(6)]);
        $this->getJson('/api/documents/'.$d->id)->assertJsonPath('data.stale', true);
        $this->postJson('/api/documents/'.$d->id.'/retry')->assertOk()->assertJsonPath('data.extraction_status', 'queued');
        $this->assertSame('failed', DocumentExtractionRun::find($old)->status);
        $this->assertNotSame($old, $d->fresh()->current_run_id);
        $this->assertSame(2, $d->runs()->count());
        Queue::assertPushed(ProcessReportDocument::class, 2);
    }

    public function test_real_python_job_stores_raw_text_tables_assets_and_is_idempotent(): void
    {
        $d = $this->one();
        $processor = app(PythonDocumentProcessor::class);
        $job = new ProcessReportDocument($d->current_run_id);
        $job->handle($processor);
        $run = $d->currentRun()->first();
        $this->assertSame('extracted', $run->status);
        $this->assertNotNull($run->raw_json);
        $this->assertTrue($run->content()->where('content_type', 'table')->exists());
        $n = $run->content()->count();
        $job->handle($processor);
        $this->assertSame($n, $run->content()->count());
        $this->getJson('/api/documents/'.$d->id.'/extraction?type=table')->assertOk()->assertJsonPath('content.total', 1);
        $this->get('/api/documents/'.$d->id.'/raw')->assertOk();
        $this->postJson('/api/documents/'.$d->id.'/retry')->assertOk();
        $next = $d->fresh()->current_run_id;
        (new ProcessReportDocument($next))->handle($processor);
        $this->assertSame($n, DocumentExtractionRun::find($next)->content()->count());
        $this->assertSame($n, $run->content()->count());
        $other = $this->upload([$this->csv('other.csv', 'a,b')])->assertCreated()->json('data.0.id');
        $this->getJson('/api/documents/'.$other.'/extraction?run_id='.$run->id)->assertNotFound();
    }

    public function test_real_reference_pdf_job_keeps_all_pages_images_and_preview_private(): void
    {
        config(['documents.ocr_engine' => 'none']);
        $id = $this->upload([$this->reference('Keyword_Ranking')])->assertCreated()->json('data.0.id');
        $d = ReportDocument::findOrFail($id);
        (new ProcessReportDocument($d->current_run_id))->handle(app(PythonDocumentProcessor::class));
        $run = $d->currentRun()->first();
        $this->assertSame(5, $run->statistics['metadata']['page_count']);
        $this->assertSame(5, $run->processed_units);
        $this->assertGreaterThan(0, $run->assets()->count());
        $asset = $run->assets()->where('mime_type', 'image/png')->first();
        $this->get('/api/documents/'.$id.'/assets/'.$asset->id)->assertOk();
        $this->get('/api/documents/'.$id.'/preview')->assertOk();
        $this->assertSame('draft', $this->period->fresh()->status);
        $this->assertSame(2, $this->period->fresh()->version);
    }

    public function test_missing_python_and_corrupt_document_fail_with_retryable_status(): void
    {
        $d = $this->one();
        $python = config('documents.python');
        config(['documents.python' => 'missing-python-executable']);
        try {
            (new ProcessReportDocument($d->current_run_id))->handle(app(PythonDocumentProcessor::class));
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Python', $e->getMessage());
        }
        $this->assertSame('failed', $d->fresh()->extraction_status);
        $this->getJson('/api/documents/'.$d->id)->assertJsonPath('data.can_retry', true);
        config(['documents.python' => $python]);
        $id = $this->upload([UploadedFile::fake()->createWithContent('broken.pdf', "%PDF-1.7\nnot readable")])->assertCreated()->json('data.0.id');
        $broken = ReportDocument::findOrFail($id);
        try {
            (new ProcessReportDocument($broken->current_run_id))->handle(app(PythonDocumentProcessor::class));
            $this->fail('Expected corrupt PDF failure');
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame('failed', $broken->fresh()->extraction_status);
    }

    public function test_utf16_csv_and_literal_search_preserve_event_names_and_percent_values(): void
    {
        $file = $this->csv('events.csv', mb_convert_encoding("Event,Count\ncontact_us,37\nCTR,0.68%", 'UTF-16LE', 'UTF-8'));
        // BOM makes byte encoding explicit for content validation.
        $file = UploadedFile::fake()->createWithContent('events.csv', "\xFF\xFE".file_get_contents($file->getRealPath()));
        $id = $this->upload([$file])->assertCreated()->json('data.0.id');
        $d = ReportDocument::findOrFail($id);
        (new ProcessReportDocument($d->current_run_id))->handle(app(PythonDocumentProcessor::class));
        $this->getJson('/api/documents/'.$id.'/extraction?type=table&search=contact_us')->assertOk()->assertJsonPath('content.total', 1);
        $this->getJson('/api/documents/'.$id.'/extraction?type=table&search=0.68%25')->assertOk()->assertJsonPath('content.total', 1);
        $this->getJson('/api/documents/'.$id.'/assets?type[]=image')->assertUnprocessable();
        $this->getJson('/api/documents/'.$id.'/extraction?run_id[]=x')->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/documents/'.$id)->assertUnauthorized();
    }

    public function test_claim_database_failure_is_caught_and_persisted_with_specific_safe_error(): void
    {
        $d = $this->one();
        $calls = 0;
        DB::partialMock()->shouldReceive('transaction')->andReturnUsing(function ($callback, $attempts = 1) use (&$calls) {
            if (++$calls === 1) {
                throw new QueryException('sqlite', 'update document_extraction_runs set secret=?', ['private-binding'], new \PDOException('database is locked'));
            }

            return $callback();
        });
        try {
            (new ProcessReportDocument($d->current_run_id))->handle(app(PythonDocumentProcessor::class));
            $this->fail('Expected claim error');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SQLite database was busy', $e->getMessage());
        }
        $this->assertSame('failed', $d->fresh()->extraction_status);
        $this->assertStringNotContainsString('private-binding', $d->currentRun()->first()->error_message);
        $this->assertNull($d->currentRun()->first()->started_at);
    }
}
