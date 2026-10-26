<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadDocumentsRequest;
use App\Jobs\ProcessReportDocument;
use App\Models\DocumentExtractionRun;
use App\Models\ProjectActivityLog;
use App\Models\ReportDocument;
use App\Models\ReportingPeriod;
use App\Services\DocumentAccess;
use App\Services\DocumentFileValidator;
use App\Services\PythonDocumentProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentController extends Controller
{
    public function __construct(public DocumentAccess $access) {}

    private function stale(DocumentExtractionRun $run): bool
    {
        return ($run->status === 'queued' && $run->created_at->lt(now()->subMinutes(5))) || ($run->status === 'processing' && ($run->heartbeat_at ?? $run->started_at)->lt(now()->subSeconds(config('documents.timeout') + 120)));
    }

    private function data(Request $r, ReportDocument $d): array
    {
        $d->loadMissing('currentRun');
        $run = $d->currentRun;
        $manage = $this->access->canManage($r->user(), $d);

        return [...$d->toArray(), 'stale' => $run ? $this->stale($run) : false, 'can_remove' => $manage, 'can_retry' => $manage && $run && (! in_array($run->status, ['queued', 'processing']) || $this->stale($run))];
    }

    public function index(Request $r, string $project, string $report)
    {
        $v = $this->access->report($r->user(), $project, $report);

        return $v->documents()->with('currentRun')->latest()->paginate(20)->through(fn ($d) => $this->data($r, $d));
    }

    public function projectIndex(Request $r, string $project)
    {
        $p = $this->access->workspace->projects($r->user())->findOrFail($project);
        abort_if($this->access->workspace->role($r->user()) === 'client_viewer', 403);

        return ReportDocument::where('project_id', $p->id)->with(['currentRun', 'project.client'])->latest()->paginate(20)->through(fn ($d) => $this->data($r, $d));
    }

    public function health(Request $r, PythonDocumentProcessor $processor)
    {
        abort_if($this->access->workspace->role($r->user()) === 'client_viewer', 403);

        return response()->json(Cache::remember('document-processor-health', 30, fn () => $processor->doctor()));
    }

    public function store(UploadDocumentsRequest $r, string $project, string $report, DocumentFileValidator $validator)
    {
        $v = $this->access->report($r->user(), $project, $report);
        abort_unless($this->access->canUpload($r->user(), $v), 403, 'Uploads require an active project and Draft report.');
        $files = $r->file('files');
        $meta = [];
        $seen = [];
        $size = 0;
        foreach ($files as $i => $f) {
            $m = $validator->inspect($f, "files.$i");
            $size += $m['file_size'];
            if (isset($seen[$m['checksum']]) || $v->documents()->where('active_checksum', $m['checksum'])->exists()) {
                throw ValidationException::withMessages(["files.$i" => 'This exact file is already attached to this report or selected twice.']);
            }
            $seen[$m['checksum']] = true;
            $meta[] = $m;
        }
        abort_if($size > config('documents.max_total_mb') * 1024 * 1024, 422, 'This batch exceeds the total upload limit.');
        $paths = [];
        $docs = [];
        try {
            DB::transaction(function () use ($r, $v, $files, $meta, &$paths, &$docs) {
                $locked = ReportingPeriod::whereKey($v->id)->lockForUpdate()->firstOrFail();
                abort_unless($this->access->canUpload($r->user(), $locked), 409, 'The reporting period is no longer editable.');
                if ($r->filled('batch_id')) {
                    $batch = $locked->documents()->where('batch_id', $r->input('batch_id'));
                    abort_if((clone $batch)->count() + count($files) > config('documents.max_batch'), 422, 'This batch exceeds the file-count limit.');
                    abort_if((clone $batch)->sum('file_size') + array_sum(array_column($meta, 'file_size')) > config('documents.max_total_mb') * 1024 * 1024, 422, 'This batch exceeds the total upload limit.');
                }
                abort_if(\App\Models\ReportGeneration::where('reporting_period_id',$locked->id)->whereIn('status',['queued','processing'])->exists() || \App\Models\NormalizationRun::where('reporting_period_id',$locked->id)->whereIn('status',['queued','processing'])->exists(),409,'Wait for report generation to finish before changing sources.');
                if ($r->attributes->get('replacement_id')) {
                    $old=$this->access->document($r->user(),$r->attributes->get('replacement_id'));
                    abort_unless($old->reporting_period_id===$locked->id && $this->access->canManage($r->user(),$old),403);
                    app(\App\Services\DraftInvalidation::class)->assertIdle([$locked->id]);
                    $old->forceFill(['active_checksum'=>null,'deleted_by'=>$r->user()->id])->save();$old->delete();
                    app(\App\Services\WorkspaceLifecycle::class)->audit($r->user(),'document',$old->id,'replaced');
                }
                app(\App\Services\DraftInvalidation::class)->invalidate($locked);
                foreach ($files as $i => $f) {
                    $m = $meta[$i];
                    $id = (string) Str::ulid();
                    $stored = $id.'.'.$m['extension'];
                    $path = 'report-documents/'.$id.'/original.'.$m['extension'];
                    if (! Storage::disk('local')->putFileAs('report-documents/'.$id, $f, 'original.'.$m['extension'])) {
                        throw new \RuntimeException('Unable to store document.');
                    }
                    $paths[] = $path;
                    $d = new ReportDocument([...$m, 'reporting_period_id' => $v->id, 'project_id' => $v->project_id, 'stored_filename' => $stored, 'storage_path' => $path, 'active_checksum' => $m['checksum'], 'batch_id' => $r->input('batch_id'), 'uploaded_by' => $r->user()->id]);
                    $d->id = $id;
                    $d->save();
                    $run = $d->runs()->create(['status' => 'queued', 'stage' => 'Waiting for document queue worker']);
                    $d->update(['current_run_id' => $run->id]);
                    $docs[] = $d;
                    $this->log($r, $d, 'document.uploaded', ['run_id' => $run->id, 'checksum' => $d->checksum]);
                }
            });
        } catch (\Throwable $e) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            if ($e instanceof QueryException && in_array((string) $e->getCode(), ['23000', '23505']) && str_contains($e->getMessage(), 'active_report_document_checksum')) {
                throw ValidationException::withMessages(['files' => 'An identical file was uploaded concurrently. Reload the document list.']);
            }
            throw $e;
        }
        foreach ($docs as $d) {
            $this->dispatch($d);
        }

        return response()->json(['data' => array_map(fn ($d) => $this->data($r, $d->fresh()), $docs), 'message' => 'Files stored privately. Extraction is queued separately.'], 201);
    }

    public function replace(UploadDocumentsRequest $r, string $document, DocumentFileValidator $validator)
    {
        $d=$this->access->document($r->user(),$document);
        abort_unless($this->access->canManage($r->user(),$d),403);
        abort_unless(count($r->file('files'))===1,422,'Choose exactly one replacement file.');
        $r->validate(['confirmed'=>'required|accepted']);
        $r->attributes->set('replacement_id',$document);
        return $this->store($r,$d->project_id,$d->reporting_period_id,$validator);
    }

    private function dispatch(ReportDocument $d): void
    {
        try {
            ProcessReportDocument::dispatch($d->current_run_id);
        } catch (\Throwable $e) {
            report($e);
            $d->currentRun()->update(['status' => 'failed', 'error_message' => 'Queue dispatch failed. Check the queue connection and retry.', 'completed_at' => now()]);
            $d->update(['extraction_status' => 'failed']);
        }
    }

    public function show(Request $r, string $document)
    {
        $d = $this->access->document($r->user(), $document);

        return response()->json(['data' => [...$this->data($r, $d), 'runs' => $d->runs()->latest()->get()]]);
    }

    private function run(Request $r, ReportDocument $d): DocumentExtractionRun
    {
        $r->validate(['run_id' => 'nullable|string|max:26']);

        return $d->runs()->whereKey($r->input('run_id', $d->current_run_id))->firstOrFail();
    }

    public function extraction(Request $r, string $document)
    {
        $d = $this->access->document($r->user(), $document);
        $run = $this->run($r, $d);
        $v = $r->validate(['type' => 'nullable|string|max:32', 'search' => 'nullable|string|max:200', 'page' => 'nullable|integer|min:1']);
        $q = $run->content()->orderBy('ordinal');
        if ($v['type'] ?? null) {
            $q->where('content_type', $v['type']);
        }
        if ($v['search'] ?? null) {
            $q->whereRaw("original_text LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $v['search']).'%']);
        }

        return response()->json(['run' => $run, 'content' => $q->paginate(30), 'types' => $run->content()->select('content_type')->distinct()->pluck('content_type')]);
    }

    public function assets(Request $r, string $document)
    {
        $d = $this->access->document($r->user(), $document);
        $run = $this->run($r, $d);
        $r->validate(['type' => 'nullable|string|max:32', 'page' => 'nullable|integer|min:1']);
        $q = $run->assets()->orderBy('asset_index');
        if ($r->query('type')) {
            $q->where('asset_type', $r->query('type'));
        }

        return $q->paginate(12);
    }

    public function asset(Request $r, string $document, string $asset)
    {
        $d = $this->access->document($r->user(), $document);
        $a = $d->runs()->whereHas('assets', fn ($q) => $q->where('id', $asset))->firstOrFail()->assets()->findOrFail($asset);

        return $this->file($a->storage_path, 'evidence-'.$a->id.'.'.pathinfo($a->storage_path, PATHINFO_EXTENSION), $a->mime_type, true);
    }

    public function preview(Request $r, string $document)
    {
        $d = $this->access->document($r->user(), $document);
        abort_unless(in_array($d->extension, ['pdf', 'png', 'jpg', 'jpeg', 'webp']), 422, 'Use extracted text, tables and images for Office document previews.');

        return $this->file($d->storage_path, $d->original_filename, $d->mime_type, true);
    }

    public function download(Request $r, string $document)
    {
        $d = $this->access->document($r->user(), $document);

        return $this->file($d->storage_path, $d->original_filename, $d->mime_type, false);
    }

    public function raw(Request $r, string $document)
    {
        $d = $this->access->document($r->user(), $document);
        $run = $this->run($r, $d);
        abort_unless($run->raw_json !== null, 404);

        return response($run->raw_json, 200, ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="extraction-'.$run->id.'.json"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function file(string $path, string $name, string $mime, bool $inline)
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);
        $safe = $inline && in_array($mime, ['application/pdf', 'image/png', 'image/jpeg', 'image/webp']);

        return response()->file($disk->path($path), ['Content-Type' => $safe ? $mime : 'application/octet-stream', 'Content-Disposition' => ($safe ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($name), 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:"]);
    }

    public function retry(Request $r, string $document)
    {
        $d = $this->access->document($r->user(), $document);
        abort_unless($this->access->canManage($r->user(), $d), 403);
        DB::transaction(function () use ($r, &$d) {
            $d = ReportDocument::whereKey($d->id)->lockForUpdate()->firstOrFail();
            $run = $d->currentRun;
            abort_unless($this->access->canManage($r->user(), $d), 409);
            abort_if(in_array($run->status, ['queued', 'processing']) && ! $this->stale($run), 409, 'This attempt is still active. Start the document queue worker or wait for its timeout.');
            if (in_array($run->status, ['queued', 'processing'])) {
                $run->update(['status' => 'failed', 'completed_at' => now(), 'error_message' => 'Stale attempt superseded by an explicit retry.']);
            }
            $period=ReportingPeriod::whereKey($d->reporting_period_id)->lockForUpdate()->firstOrFail();
            abort_if(\App\Models\ReportGeneration::where('reporting_period_id',$period->id)->whereIn('status',['queued','processing'])->exists(),409,'Wait for report generation before retrying this source.');
            app(\App\Services\DraftInvalidation::class)->invalidate($period);
            $next = $d->runs()->create(['status' => 'queued', 'stage' => 'Waiting for document queue worker']);
            $d->update(['current_run_id' => $next->id, 'extraction_status' => 'queued']);
            $this->log($r, $d, 'document.retried', ['previous_run_id' => $run->id, 'run_id' => $next->id]);
        });
        $this->dispatch($d);

        return response()->json(['data' => $this->data($r, $d->fresh()), 'message' => 'A new extraction attempt was queued. Previous attempts remain available.']);
    }

    public function destroy(Request $r, string $document)
    {
        $d=$this->access->document($r->user(),$document);
        abort_unless($this->access->canManage($r->user(),$d),403);
        $v=$r->validate(['confirmed'=>'required|accepted','preview_token'=>'required|string|size:64']);
        app(\App\Services\WorkspaceLifecycle::class)->mutate($r->user(),'document',$document,[...$v,'action'=>'trash']);
        return response()->json(['message'=>'Source moved to Trash. Regenerate and verify this Draft before publishing.']);
    }

    private function log(Request $r, ReportDocument $d, string $action, array $properties): void
    {
        ProjectActivityLog::create(['project_id' => $d->project_id, 'reporting_period_id' => $d->reporting_period_id, 'actor_id' => $r->user()->id, 'action' => $action, 'properties' => ['document_id' => $d->id, ...$properties]]);
    }
}
