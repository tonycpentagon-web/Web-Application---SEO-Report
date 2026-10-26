<?php

namespace App\Jobs;

use App\Models\DocumentAsset;
use App\Models\DocumentExtractedContent;
use App\Models\DocumentExtractionRun;
use App\Models\ProjectActivityLog;
use App\Models\ReportDocument;
use App\Services\DocumentFailure;
use App\Services\PythonDocumentProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcessReportDocument implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 960;

    public $failOnTimeout = true;

    public function __construct(public string $runId)
    {
        $this->onQueue(config('documents.queue', 'documents'));
        $this->timeout = (int) config('documents.timeout') + 60;
    }

    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\ClientDatasetGuard];
    }

    public function handle(PythonDocumentProcessor $processor): void
    {
        try {
            $run = DB::transaction(function () {
                $run = DocumentExtractionRun::whereKey($this->runId)->lockForUpdate()->first();
                if (! $run || $run->status !== 'queued') {
                    return null;
                }
                $doc = ReportDocument::whereKey($run->document_id)->lockForUpdate()->first();
                if (! $doc || $doc->current_run_id !== $run->id) {
                    return null;
                }
                $run->update(['status' => 'processing', 'started_at' => now(), 'heartbeat_at' => now(), 'stage' => 'Opening document', 'events' => []]);
                $doc->update(['extraction_status' => 'processing']);

                return $run;
            }, 5);
            if (! $run) {
                return;
            }
            $doc = ReportDocument::findOrFail($run->document_id);
            $disk = Storage::disk('local');
            $source = $disk->path($doc->storage_path);
            if (! is_file($source) || hash_file('sha256', $source) !== $doc->checksum) {
                throw new \RuntimeException('The original file is missing or its checksum changed; extraction stopped.');
            }
            $folder = 'report-documents/'.$doc->id.'/runs/'.$run->id;
            $disk->makeDirectory($folder);
            $output = $disk->path($folder);
            $result = $processor->process($source, $output, $doc->extension, function ($event) use ($run) {
                if (! ReportDocument::whereKey($run->document_id)->where('current_run_id', $run->id)->exists()) {
                    throw new \RuntimeException('Extraction attempt was superseded or removed.');
                }
                $run->refresh();
                $events = $run->events ?? [];
                $events[] = ['at' => now()->toIso8601String(), 'message' => mb_substr((string) ($event['message'] ?? 'Processing'), 0, 255), 'completed' => max(0, (int) ($event['completed'] ?? 0)), 'total' => isset($event['total']) ? max(0, (int) $event['total']) : null];
                $run->update(['heartbeat_at' => now(), 'stage' => end($events)['message'], 'processed_units' => end($events)['completed'], 'total_units' => end($events)['total'], 'events' => $events]);
            });
            $data = $result['data'];
            if (($data['metadata']['sha256'] ?? '') !== $doc->checksum) {
                throw new \RuntimeException('Processor source checksum does not match the uploaded file.');
            }
            DB::transaction(function () use ($run, $doc, $data, $result, $folder, $disk) {
                $current = ReportDocument::whereKey($doc->id)->lockForUpdate()->first();
                $locked = DocumentExtractionRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                if (! $current || $current->current_run_id !== $run->id || $locked->status !== 'processing') {
                    return;
                }
                foreach ($data['content'] as $i => $item) {
                    if (! is_array($item) || ! is_string($item['type'] ?? null)) {
                        throw new \RuntimeException('Invalid content returned by processor.');
                    }
                    $location = $item['source'] ?? [];
                    DocumentExtractedContent::create(['document_id' => $doc->id, 'extraction_run_id' => $run->id, 'ordinal' => $i, 'content_type' => mb_substr($item['type'], 0, 32), 'page_number' => $location['page'] ?? null, 'worksheet_name' => $location['worksheet'] ?? null, 'section_name' => $item['section'] ?? null, 'original_text' => $item['text'] ?? null, 'structured_json' => $item['data'] ?? [], 'source_location' => $location, 'confidence_status' => mb_substr($item['confidence'] ?? 'unverified', 0, 32)]);
                }
                foreach ($data['assets'] as $i => $asset) {
                    $relative = $asset['path'] ?? '';
                    if (! preg_match('#^assets/asset-[0-9]{5}\.[a-z0-9]{1,12}$#D', $relative)) {
                        throw new \RuntimeException('Invalid asset location returned by processor.');
                    }
                    $path = $folder.'/'.$relative;
                    $absolute = realpath($disk->path($path));
                    $base = realpath($disk->path($folder));
                    if (! $absolute || ! str_starts_with(strtolower($absolute), strtolower($base.DIRECTORY_SEPARATOR)) || ! is_file($absolute)) {
                        throw new \RuntimeException('Extraction asset is outside its private run directory.');
                    }
                    DocumentAsset::create(['document_id' => $doc->id, 'extraction_run_id' => $run->id, 'asset_index' => $i, 'asset_type' => mb_substr($asset['type'] ?? 'evidence', 0, 32), 'source_page' => $asset['source']['page'] ?? null, 'worksheet_name' => $asset['source']['worksheet'] ?? null, 'storage_path' => $path, 'mime_type' => (new \finfo(FILEINFO_MIME_TYPE))->file($absolute), 'file_size' => filesize($absolute), 'checksum' => hash_file('sha256', $absolute), 'metadata' => ['source' => $asset['source'] ?? [], 'details' => $asset['metadata'] ?? []]]);
                }
                $warnings = [...($data['warnings'] ?? []), ...array_map(fn ($e) => ['severity' => 'error', ...$e], $data['errors'] ?? [])];
                $locked->update(['status' => $data['status'], 'processor_version' => $data['processor_version'] ?? 'unknown', 'completed_at' => now(), 'heartbeat_at' => now(), 'stage' => $data['status'] === 'extracted' ? 'Extraction completed' : 'Extraction completed with review flags', 'processed_units' => count($data['units'] ?? []), 'total_units' => $data['metadata']['page_count'] ?? $data['metadata']['worksheet_count'] ?? count($data['units'] ?? []), 'warnings' => $warnings, 'statistics' => ['counts' => $data['statistics'] ?? [], 'metadata' => $data['metadata'] ?? [], 'units' => $data['units'] ?? []], 'raw_json' => $result['json'], 'raw_path' => $folder.'/raw.json', 'error_message' => empty($data['errors']) ? null : 'Some source content could not be read; inspect the warnings and retained evidence.']);
                $current->update(['extraction_status' => $data['status']]);
                ProjectActivityLog::create(['project_id' => $doc->project_id, 'reporting_period_id' => $doc->reporting_period_id, 'actor_id' => null, 'action' => 'document.extraction_completed', 'properties' => ['document_id' => $doc->id, 'run_id' => $run->id, 'status' => $data['status']]]);
            }, 5);
        } catch (\Throwable $e) {
            $message = DocumentFailure::message($e);
            $this->recordFailure($message);
            throw new \RuntimeException($message);
        }
    }

    public function failed(?\Throwable $e): void
    {
        $this->recordFailure(DocumentFailure::message($e));
    }

    private function recordFailure(string $message): void
    {
        DB::transaction(function () use ($message) {
            $run = DocumentExtractionRun::whereKey($this->runId)->lockForUpdate()->first();
            if (! $run || ! in_array($run->status, ['queued', 'processing'])) {
                return;
            }
            $run->update(['status' => 'failed', 'error_message' => $message, 'stage' => 'Extraction failed', 'completed_at' => now(), 'heartbeat_at' => now()]);
            ReportDocument::whereKey($run->document_id)->where('current_run_id', $run->id)->update(['extraction_status' => 'failed']);
        }, 5);
    }
}
