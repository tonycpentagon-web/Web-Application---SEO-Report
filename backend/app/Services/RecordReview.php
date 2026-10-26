<?php

namespace App\Services;

use App\Models\NormalizationRun;
use App\Models\NormalizedRecord;
use App\Models\ProjectActivityLog;
use App\Models\RecordCorrection;
use App\Models\ReportDocument;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** One eligibility policy for individual and bulk review. Confidence never grants verification. */
class RecordReview
{
    public function __construct(public DocumentAccess $access, public ReportNormalization $engine) {}

    public function canReview(User $user, ReportingPeriod $p): bool
    {
        return $this->access->canUpload($user, $p) && $this->access->workspace->manageProject($user, $p->project);
    }

    public function warningIsBlocking(object $w): bool
    {
        $informational = $w->severity === 'info' || in_array($w->code, ['duplicate_metric', 'unclassified_content', 'ranking_context_missing']) || ($w->code === 'missing_value' && ! $w->normalized_record_id);

        return ! ($informational && ! in_array($w->severity, ['error', 'blocking']));
    }

    public function context(ReportingPeriod $p, bool $lockSources = false): array
    {
        $run = NormalizationRun::where('reporting_period_id', $p->id)->latest()->orderByDesc('id')->first();
        $query = ReportDocument::where('reporting_period_id', $p->id)->where('project_id', $p->project_id)->orderBy('id');
        if ($lockSources) {
            $query->lockForUpdate();
        }
        $docs = $query->with('currentRun')->get()->keyBy('id');
        $fingerprint = $this->engine->fingerprint($p, $this->engine->snapshot($p));
        $available = [];
        foreach ($docs as $doc) {
            $available[$doc->id] = Storage::disk('local')->exists($doc->storage_path);
        }
        $runIds = $docs->pluck('current_run_id')->filter()->all();
        $content = DB::table('document_extracted_content')->whereIn('extraction_run_id', $runIds)->get(['id', 'document_id', 'extraction_run_id'])->keyBy('id');
        $assets = DB::table('document_assets')->whereIn('extraction_run_id', $runIds)->get(['id', 'document_id', 'extraction_run_id', 'storage_path'])->keyBy('id');
        $peers = NormalizedRecord::where('reporting_period_id', $p->id)->where('active', true)->where('kind', 'report_metrics')->where('review_status', '!=', 'rejected')->get();

        return ['run' => $run, 'fingerprint' => $fingerprint, 'current' => $run && $run->status === 'completed' && $run->fingerprint === $fingerprint,
            'docs' => $docs, 'available' => $available, 'content' => $content, 'assets' => $assets, 'peers' => $peers,
            'warnings' => DB::table('validation_warnings')->where('normalization_run_id', $run?->id ?? 'none')->orderBy('id')->get()];
    }

    private function hashes(NormalizedRecord $row, array $context, array $warnings): array
    {
        $refs = $row->sources->where('normalization_run_id', $context['run']?->id)->sortBy('id')->map(fn ($s) => [$s->document_id, $s->extraction_run_id, $s->content_id, $s->asset_id, $s->location, $s->quote])->values()->all();

        // Warning IDs are intentionally included: a new normalization attempt requires renewed inspection.
        return ['evidence_hash' => hash('sha256', json_encode([$row->source_hash, $refs])),
            'value_hash' => hash('sha256', json_encode($row->data)),
            'context_hash' => hash('sha256', json_encode([$context['fingerprint'], $context['run']?->id, $warnings]))];
    }

    public function eligibility(NormalizedRecord $row, ReportingPeriod $p, User $user, array $context): array
    {
        $row->loadMissing('sources');
        $reasons = [];
        if (! ($context['review_permission'] ?? $this->canReview($user, $p))) {
            $reasons[] = 'Review permission is required.';
        }
        if (! $context['current'] || $row->normalization_run_id !== $context['run']?->id || ! $row->active) {
            $reasons[] = 'Normalization is stale or incomplete.';
        }
        $sources = $row->sources->where('normalization_run_id', $context['run']?->id);
        if ($sources->isEmpty()) {
            $reasons[] = 'Original source evidence is missing.';
        }
        foreach ($sources as $s) {
            $doc = $context['docs']->get($s->document_id);
            if (! $doc || ! ($context['available'][$s->document_id] ?? false) || $doc->current_run_id !== $s->extraction_run_id) {
                $reasons[] = 'Original source document is unavailable or its extraction changed.';

                continue;
            }
            if (! in_array($doc->extraction_status, ['extracted', 'needs_review', 'partially_extracted']) || ! in_array($doc->currentRun?->status, ['extracted', 'needs_review', 'partially_extracted'])) {
                $reasons[] = 'Source extraction is not acceptable for review.';
            }
            if (! $s->content_id && ! $s->asset_id) {
                $reasons[] = 'Source content or image evidence is missing.';
            }
            foreach (['content_id' => 'content', 'asset_id' => 'assets'] as $field => $collection) {
                if (! $s->$field) {
                    continue;
                }
                $evidence = $context[$collection]->get($s->$field);
                if (! $evidence || $evidence->document_id !== $doc->id || $evidence->extraction_run_id !== $s->extraction_run_id || ($field === 'asset_id' && ! Storage::disk('local')->exists($evidence->storage_path))) {
                    $reasons[] = 'Referenced source content or image is unavailable.';
                }
            }
        }
        try {
            $this->validateData($row, []);
        } catch (ValidationException $e) {
            $reasons[] = 'Normalized values failed validation: '.implode(' ', $e->validator->errors()->all());
        }
        if ($row->kind === 'unclassified') {
            $reasons[] = 'Unclassified content must be classified before approval.';
        }
        if ($row->kind === 'keyword_rankings' && empty($row->data['ranking_label'])) {
            $reasons[] = 'A source-supported ranking label is required.';
        }
        if ($row->kind === 'ai_visibility_evidence' && in_array($row->data['brand_appearance'] ?? 'unreviewed', ['unreviewed', 'unclear'])) {
            $reasons[] = 'A clear human brand-appearance decision is required.';
        }
        $warnings = [];
        foreach ($context['warnings'] as $w) {
            if ($w->normalized_record_id ? $w->normalized_record_id !== $row->id : ($w->document_id && ! $sources->contains('document_id', $w->document_id))) {
                continue;
            }
            $informational = ! $this->warningIsBlocking($w);
            $warnings[] = ['id' => $w->id, 'code' => $w->code, 'message' => $w->message, 'severity' => $informational ? 'info' : 'blocking', 'blocking' => ! $informational];
        }
        // Effective conflicting metrics cannot be waived with an acknowledgement.
        if ($row->kind === 'report_metrics') {
            foreach ($context['peers'] as $peer) {
                if ($peer->id !== $row->id && ($peer->data['metric_key'] ?? null) === ($row->data['metric_key'] ?? null) && ($peer->data['value'] ?? null) != ($row->data['value'] ?? null)) {
                    $reasons[] = 'Conflicting metric values remain. Correct or reject the conflicting observation first.';
                }
            }
        }
        $hashes = $this->hashes($row, $context, $warnings);
        if (isset($context['verifications'])) {
            $history = $context['verifications']->get($row->id, collect());
            $verification = $history->first(fn ($v) => $v->evidence_hash === $hashes['evidence_hash'] && $v->value_hash === $hashes['value_hash'] && $v->context_hash === $hashes['context_hash']);
            $previousVerification = $verification ?: $history->first();
        } else {
            $verification = DB::table('record_verifications')->where('normalized_record_id', $row->id)->where($hashes)->latest()->first();
            $previousVerification = $verification ?: DB::table('record_verifications')->where('normalized_record_id', $row->id)->latest()->orderByDesc('id')->first();
        }
        if (! $verification) {
            $reasons[] = 'Explicit human source verification is required.';
        }
        $resolved = $verification ? array_column(json_decode($verification->resolutions, true), 'warning_id') : [];
        foreach ($warnings as &$warning) {
            $warning['resolved'] = in_array($warning['id'], $resolved);
            if ($warning['blocking'] && ! $warning['resolved']) {
                $reasons[] = $warning['code'].': '.$warning['message'];
            }
        }
        unset($warning);
        if ($row->review_status === 'rejected') {
            $reasons[] = 'Rejected records must be reopened individually before approval.';
        }
        if ($row->review_status === 'approved') {
            $reasons[] = 'Already approved; the existing decision will be preserved.';
        }

        return ['eligible' => ! $reasons, 'verified' => (bool) $verification, 'verification_id' => $verification?->id, 'verified_by' => $verification?->actor_id,
            'verified_at' => $verification?->created_at, 'verification_state' => $verification ? 'current' : ($previousVerification ? 'outdated' : 'not_recorded'),
            'last_verified_at' => $previousVerification?->created_at, 'reasons' => array_values(array_unique($reasons)), 'warnings' => $warnings, 'hashes' => $hashes];
    }

    public function verify(NormalizedRecord $row, ReportingPeriod $p, User $user, array $context, string $reason, array $resolutions): void
    {
        abort_if(mb_strlen(trim($reason)) < 10, 422, 'Source inspection notes must contain at least 10 characters.');
        $e = $this->eligibility($row, $p, $user, $context);
        $structural = array_filter($e['reasons'], fn ($r) => ! collect($e['warnings'])->contains(fn ($w) => str_starts_with($r, $w['code'].': ')) && ! in_array($r, ['Explicit human source verification is required.', 'Already approved; the existing decision will be preserved.', 'Rejected records must be reopened individually before approval.']));
        abort_if((bool) $structural, 422, implode(' ', $structural));
        $required = collect($e['warnings'])->where('blocking', true)->pluck('id')->all();
        $submitted = array_column($resolutions, 'warning_id');
        abort_if(array_diff($required, $submitted) || array_diff($submitted, $required), 422, 'Provide a specific resolution for each blocking warning on this record.');
        $query = DB::table('record_verifications')->where('normalized_record_id', $row->id)->where($e['hashes']);
        if ($query->exists()) {
            return;
        }
        DB::table('record_verifications')->insert(['id' => (string) Str::ulid(), 'normalized_record_id' => $row->id, 'actor_id' => $user->id, ...$e['hashes'], 'reason' => $reason, 'resolutions' => json_encode($resolutions), 'created_at' => now(), 'updated_at' => now()]);
        ProjectActivityLog::create(['project_id' => $p->project_id, 'reporting_period_id' => $p->id, 'actor_id' => $user->id, 'action' => 'normalization.source_verified', 'properties' => ['record_id' => $row->id, 'reason' => $reason, 'resolutions' => $resolutions]]);
    }

    public function validateData(NormalizedRecord $record, array $input): array
    {
        $base = $record->data;
        $schemas = [
            'report_metrics' => ['value' => 'required|numeric|min:0'],
            'traffic_channels' => ['channel' => 'required|string|max:200', 'total_users' => 'required|integer|min:0', 'new_users' => 'nullable|integer|min:0'],
            'device_metrics' => ['device' => 'required|string|max:100', 'clicks' => 'required|integer|min:0', 'impressions' => 'nullable|integer|min:0'],
            'query_metrics' => ['query' => 'required|string|max:1000', 'clicks' => 'required|integer|min:0', 'impressions' => 'nullable|integer|min:0', 'ctr' => 'nullable|numeric|min:0|max:100', 'position' => 'nullable|numeric|min:0'],
            'landing_page_metrics' => ['url' => 'required|url:http,https|max:4096', 'clicks' => 'required|integer|min:0', 'impressions' => 'nullable|integer|min:0', 'ctr' => 'nullable|numeric|min:0|max:100'],
            'keyword_rankings' => ['keyword' => 'required|string|max:500', 'alternative_labels' => 'sometimes|array|max:20', 'alternative_labels.*' => 'string|max:500', 'ranking_label' => 'nullable|string|max:80', 'category' => 'required|in:primary,additional,unspecified', 'ranking_date' => 'nullable|date_format:Y-m-d', 'search_location' => 'nullable|string|max:200'],
            'seo_activities' => ['category' => 'required|in:article_submission,profile_creation,guest_post,social_bookmarking,ppt_submission,image_submission,other,backlink_reference', 'url' => 'required|url:http,https|max:4096'],
            'ai_visibility_evidence' => ['query' => 'required|string|max:500', 'platform' => 'nullable|string|max:100', 'brand_appearance' => 'required|in:unreviewed,appears,absent,unclear', 'notes' => 'nullable|string|max:3000'],
            'event_metrics' => ['event_name' => 'required|string|max:200', 'count' => 'required|integer|min:0'],
            'unclassified' => [],
        ];
        $rules = $schemas[$record->kind];
        $keys = array_values(array_filter(array_keys($rules), fn ($k) => ! str_contains($k, '.')));
        foreach (array_keys($input) as $k) {
            if (! in_array($k, $keys) && (! array_key_exists($k, $base) || $input[$k] !== $base[$k])) {
                throw ValidationException::withMessages(['data' => 'Only the editable value fields may change. Source references, metric definitions and units are immutable.']);
            }
        }
        $validated = validator(array_intersect_key(array_replace($base, $input), array_flip($keys)), $rules)->validate();
        $data = array_replace($base, $validated);
        if ($record->kind === 'report_metrics') {
            if (in_array($data['unit'], ['count', 'seconds']) && floor((float) $data['value']) !== (float) $data['value']) {
                throw ValidationException::withMessages(['data.value' => 'Count and duration values must be whole numbers.']);
            }
            if ($data['unit'] === 'percent' && (float) $data['value'] > 100) {
                throw ValidationException::withMessages(['data.value' => 'Percentages cannot exceed 100.']);
            }
            $data['value'] = (float) $data['value'];
            if (floor($data['value']) === $data['value']) {
                $data['value'] = (int) $data['value'];
            }
        }
        if ($record->kind === 'keyword_rankings') {
            $label = $data['ranking_label'] ?? '';
            $data['exact_position'] = null;
            $data['page_group'] = null;
            $data['group'] = 'unknown';
            if (preg_match('/^(\d+)(?:st|nd|rd|th)\s+Page$/i', $label, $m)) {
                $data['page_group'] = (int) $m[1];
                $data['group'] = 'page_'.$m[1];
            } elseif (preg_match('/^#?([1-9]\d*)$/', $label, $m)) {
                $data['exact_position'] = (int) $m[1];
                $data['group'] = 'exact_position';
            } elseif (preg_match('/^(Not Ranked|Unranked|N\/A)$/i', $label)) {
                $data['group'] = 'not_ranked';
            } elseif ($label !== '') {
                throw ValidationException::withMessages(['data.ranking_label' => 'Use an explicit numerical position, a page label, Not Ranked, or leave it empty.']);
            }
        }

        return $data;
    }

    public function audit(NormalizedRecord $row, ReportingPeriod $p, User $user, array $before, string $reason, string $method, ?string $requestKey = null): void
    {
        $meta = ['record_id' => $row->id, 'report_id' => $p->id, 'previous_decision' => $before['review_status'], 'decision' => $row->review_status, 'revision' => $row->revision, 'source_references' => $row->sources->pluck('id')->all(), 'source_data' => $row->source_data, 'corrected_value' => $row->manual_override, 'request_key' => $requestKey];
        RecordCorrection::create(['normalized_record_id' => $row->id, 'actor_id' => $user->id, 'reason' => $reason, 'approval_method' => $method, 'review_metadata' => $meta, 'before_value' => $before, 'after_value' => $row->toArray()]);
        ProjectActivityLog::create(['project_id' => $p->project_id, 'reporting_period_id' => $p->id, 'actor_id' => $user->id, 'action' => 'normalization.record_reviewed', 'properties' => [...$meta, 'method' => $method, 'reason' => $reason]]);
    }
}
