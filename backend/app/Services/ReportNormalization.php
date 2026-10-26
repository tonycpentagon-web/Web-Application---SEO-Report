<?php

namespace App\Services;

use App\Models\MetricSourceReference;
use App\Models\NormalizationRun;
use App\Models\NormalizedRecord;
use App\Models\ProjectActivityLog;
use App\Models\RecordCorrection;
use App\Models\ReportDocument;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class ReportNormalization
{
    public const VERSION = 'pentagon-normalizer/1.0';

    public const KINDS = ['report_metrics', 'traffic_channels', 'device_metrics', 'query_metrics', 'landing_page_metrics', 'keyword_rankings', 'seo_activities', 'ai_visibility_evidence', 'event_metrics', 'unclassified'];

    public function snapshot(ReportingPeriod $report): array
    {
        return ReportDocument::where('reporting_period_id', $report->id)->orderBy('id')->get()->map(fn ($d) => ['id' => $d->id, 'run_id' => $d->current_run_id, 'checksum' => $d->checksum, 'status' => $d->extraction_status])->all();
    }

    public function fingerprint(ReportingPeriod $r, array $snapshot): string
    {
        return hash('sha256', json_encode(['normalizer' => self::VERSION, 'start' => $r->start_date->format('Y-m-d'), 'end' => $r->end_date->format('Y-m-d'), 'sources' => $snapshot]));
    }

    public function manifest(NormalizationRun $run): array
    {
        $report = ReportingPeriod::findOrFail($run->reporting_period_id);
        $docs = [];
        foreach ($run->input_snapshot as $s) {
            $d = ReportDocument::findOrFail($s['id']);
            $attempt = $d->runs()->findOrFail($s['run_id']);
            $docs[] = ['id' => $d->id, 'extraction_run_id' => $attempt->id, 'status' => $s['status'], 'extension' => $d->extension,
                'content' => $attempt->content()->orderBy('ordinal')->get()->map(fn ($c) => ['id' => $c->id, 'ordinal' => $c->ordinal, 'type' => $c->content_type, 'text' => $c->original_text ?? '', 'data' => $c->structured_json ?? [], 'source' => $c->source_location ?? [], 'confidence' => $c->confidence_status])->all(),
                'assets' => $attempt->assets()->orderBy('asset_index')->get()->map(fn ($a) => ['id' => $a->id, 'type' => $a->asset_type, 'source' => $a->metadata['source'] ?? [], 'metadata' => $a->metadata['details'] ?? []])->all()];
        }

        return ['report' => ['id' => $report->id, 'start_date' => $report->start_date->format('Y-m-d'), 'end_date' => $report->end_date->format('Y-m-d')], 'documents' => $docs];
    }

    public function execute(string $runId): void
    {
        try {
            $run = DB::transaction(function () use ($runId) {
                $r = NormalizationRun::whereKey($runId)->lockForUpdate()->firstOrFail();
                if ($r->status !== 'queued') {
                    return null;
                }$r->update(['status' => 'processing', 'started_at' => now()]);

                return $r;
            }, 5);
            if (! $run) {
                return;
            }
            $manifest = $this->manifest($run);
            $p = new Process([app(PythonDocumentProcessor::class)->binary(), base_path('../document-processor/normalize.py')], base_path(), ['PYTHONUTF8' => '1']);
            $p->setTimeout(120);
            $input = json_encode($manifest, JSON_THROW_ON_ERROR);
            if (strlen($input) > 32 * 1024 * 1024) {
                throw new \RuntimeException('Normalization input exceeds 32 MB. Split oversized source reports into smaller reporting periods.');
            }
            $p->setInput($input);
            $p->run();
            if (! $p->isSuccessful()) {
                throw new \RuntimeException('Python normalization failed. Check Python availability and the normalization test suite. Source extraction is unchanged.');
            }
            if (strlen($p->getOutput()) > 32 * 1024 * 1024) {
                throw new \RuntimeException('Normalization output exceeds the 32 MB safety limit.');
            }
            $result = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            if (($result['schema_version'] ?? 0) !== 1 || ($result['processor_version'] ?? null) !== self::VERSION || ! isset($result['records'],$result['warnings'],$result['classifications'])) {
                throw new \RuntimeException('Normalization returned an invalid schema.');
            }
            $this->persist($run, $manifest, $result);
        } catch (\Throwable $e) {
            $this->fail($runId, $e);
            throw new \RuntimeException(DocumentFailure::message($e));
        }
    }

    public function fail(string $id, ?\Throwable $e): void
    {
        NormalizationRun::whereKey($id)->whereIn('status', ['queued', 'processing'])->update(['status' => 'failed', 'completed_at' => now(), 'error_message' => DocumentFailure::message($e)]);
    }

    public function persist(NormalizationRun $run, array $manifest, array $result): void
    {
        $allowed = [];
        $content = [];
        $assets = [];
        foreach ($manifest['documents'] as $d) {
            $allowed[$d['id']] = $d['extraction_run_id'];
            foreach ($d['content'] as $c) {
                $content[$c['id']] = $d['id'];
            }foreach ($d['assets'] as $a) {
                $assets[$a['id']] = $d['id'];
            }
        }
        DB::transaction(function () use ($run, $result, $allowed, $content, $assets) {
            $locked = NormalizationRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'processing') {
                return;
            }
            $period = ReportingPeriod::whereKey($locked->reporting_period_id)->lockForUpdate()->firstOrFail();
            if ($this->fingerprint($period, $this->snapshot($period)) !== $locked->fingerprint) {
                throw new \RuntimeException('Sources or report dates changed during normalization. Re-run to use the current evidence.');
            }
            $map = [];
            $extra = [];
            foreach ($result['records'] as $row) {
                if (! in_array($row['kind'] ?? null, self::KINDS) || ! preg_match('/^[a-f0-9]{64}$/D', $row['source_key'] ?? '') || empty($row['sources']) || ! is_array($row['data'] ?? null)) {
                    throw new \RuntimeException('Normalization record lacks a supported type or source evidence.');
                }
                foreach ($row['sources'] as $ref) {
                    $doc = $ref['document_id'] ?? '';
                    if (($allowed[$doc] ?? null) !== ($ref['extraction_run_id'] ?? null) || (! empty($ref['content_id']) && ($content[$ref['content_id']] ?? null) !== $doc) || (! empty($ref['asset_id']) && ($assets[$ref['asset_id']] ?? null) !== $doc) || empty($ref['content_id']) && empty($ref['asset_id'])) {
                        throw new \RuntimeException('Normalization source reference does not belong to this reporting period.');
                    }
                }
                $record = NormalizedRecord::firstOrNew(['reporting_period_id' => $period->id, 'kind' => $row['kind'], 'source_key' => $row['source_key']]);
                $hash = hash('sha256', json_encode($row['data']));
                $changed = $record->exists && ($record->source_hash !== $hash || ! $record->active);
                $before = $record->exists ? $record->toArray() : null;
                $record->fill(['project_id' => $period->project_id, 'normalization_run_id' => $run->id, 'section_key' => $row['section'], 'source_data' => $row['data'], 'raw_value' => $row['raw'], 'confidence' => $row['confidence'], 'source_hash' => $hash, 'active' => true]);
                if ($changed) {
                    $record->revision = ($record->revision ?? 1) + 1;
                    $record->review_status = 'pending';
                    $record->reviewed_by = null;
                    $record->reviewed_at = null;
                }
                if (! $record->exists) {
                    $record->review_status = 'pending';
                }
                $record->save();
                $map[$row['source_key']] = $record->id;
                if ($changed) {
                    $extra[] = ['code' => 'source_value_changed', 'message' => 'A source value changed. Previous manual corrections were retained, and approval was returned to Pending.', 'record_key' => $row['source_key'], 'source' => [], 'severity' => 'warning'];
                    RecordCorrection::create(['normalized_record_id' => $record->id, 'actor_id' => $run->created_by, 'reason' => 'Source changed during normalization; review decision reopened.', 'before_value' => $before, 'after_value' => $record->toArray()]);
                }
                foreach ($row['sources'] as $ref) {
                    $reference = hash('sha256', json_encode([$ref['document_id'], $ref['extraction_run_id'], $ref['content_id'] ?? null, $ref['asset_id'] ?? null, $ref['source']]));
                    MetricSourceReference::updateOrCreate(['normalized_record_id' => $record->id, 'reference_key' => $reference], ['normalization_run_id' => $run->id, 'document_id' => $ref['document_id'], 'extraction_run_id' => $ref['extraction_run_id'], 'content_id' => $ref['content_id'], 'asset_id' => $ref['asset_id'], 'location' => $ref['source'], 'quote' => $ref['quote'], 'raw_value' => $ref['raw_value']]);
                }
            }
            NormalizedRecord::where('reporting_period_id', $period->id)->where('normalization_run_id', '!=', $run->id)->update(['active' => false]);
            foreach ([...$result['warnings'], ...$extra] as $w) {
                if (! empty($w['document_id']) && ! isset($allowed[$w['document_id']])) {
                    throw new \RuntimeException('Validation warning has an unauthorized source.');
                }
                DB::table('validation_warnings')->insert(['id' => (string) Str::ulid(), 'normalization_run_id' => $run->id, 'normalized_record_id' => $map[$w['record_key'] ?? ''] ?? null, 'document_id' => $w['document_id'] ?? null, 'code' => $w['code'], 'severity' => $w['severity'] ?? 'warning', 'message' => $w['message'], 'source' => json_encode($w['source'] ?? []), 'created_at' => now(), 'updated_at' => now()]);
            }
            $counts = NormalizedRecord::where('reporting_period_id', $period->id)->where('active', true)->select('kind', DB::raw('count(*) as count'))->groupBy('kind')->pluck('count', 'kind')->all();
            foreach ($result['sections'] as $section) {
                DB::table('report_sections')->updateOrInsert(['reporting_period_id' => $period->id, 'section_key' => $section], ['id' => DB::table('report_sections')->where('reporting_period_id', $period->id)->where('section_key', $section)->value('id') ?? (string) Str::ulid(), 'label' => ucwords(str_replace('_', ' ', $section)), 'record_count' => NormalizedRecord::where('reporting_period_id', $period->id)->where('active', true)->where('section_key', $section)->count(), 'created_at' => DB::table('report_sections')->where('reporting_period_id', $period->id)->where('section_key', $section)->value('created_at') ?? now(), 'updated_at' => now()]);
            }
            $period->forceFill(['requires_regeneration' => false])->save();
            $locked->update(['status' => 'completed', 'completed_at' => now(), 'processor_version' => $result['processor_version'], 'summary' => ['counts' => $counts, 'classifications' => $result['classifications'], 'warning_count' => count($result['warnings']) + count($extra)]]);
            ProjectActivityLog::create(['project_id' => $period->project_id, 'reporting_period_id' => $period->id, 'actor_id' => $run->created_by, 'action' => 'normalization.completed', 'properties' => ['normalization_run_id' => $run->id, 'counts' => $counts]]);
        }, 5);
        $period = ReportingPeriod::findOrFail($run->reporting_period_id);
        if ($run->fresh()->status === 'completed' && in_array($period->status, ['draft', 'needs_review'])) {
            app(EvidenceValidation::class)->evaluate($period, User::findOrFail($run->created_by));
        }
    }
}
