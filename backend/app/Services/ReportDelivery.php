<?php

namespace App\Services;

use App\Http\Controllers\ReportSummaryController;
use App\Models\NormalizationRun;
use App\Models\NormalizedRecord;
use App\Models\ReportingPeriod;
use App\Models\ReportPublication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReportDelivery
{
    public const SECTION_MAP = ['website_analytics' => 'website_traffic', 'search_console' => 'organic_search', 'keyword_rankings' => 'keywords', 'seo_activities' => 'seo_work'];

    public const SECTIONS = ['executive_summary', 'overview', 'website_traffic', 'organic_search', 'keywords', 'seo_work', 'ai_visibility', 'key_events', 'sources'];

    public function __construct(public ReportDashboard $dashboard, public ReportSummaryController $summaries) {}

    public function readiness(ReportingPeriod $p, User $user, bool $lock = false): array
    {
        $d = $this->dashboard->build($p, $user, true, $lock);
        $reasons = $d['publication']['reasons'];
        if ($p->requires_regeneration) {
            $reasons[] = 'Sources changed or report data was reset. Regenerate and verify this Draft.';
        }
        if ($p->start_date > $p->end_date) {
            $reasons[] = 'The reporting period is invalid.';
        }
        if ($p->project->status !== 'active' || $p->project->client?->status === 'archived') {
            $reasons[] = 'Restore the archived client/project first.';
        }
        $missingMetrics = array_values(array_diff(config('delivery.required_metrics'), array_column($d['kpis'], 'metric_key')));
        $missingSections = array_values(array_diff(config('delivery.required_sections'), array_unique(array_map(fn ($r) => self::SECTION_MAP[$r['section_key']] ?? $r['section_key'], $d['records']))));
        foreach ($missingMetrics as $key) {
            $reasons[] = 'Missing required approved metric: '.$key;
        }
        foreach ($missingSections as $key) {
            $reasons[] = 'Missing required section: '.$key;
        }
        $docs = $p->documents()->whereNull('deleted_at')->get();
        $missingDocuments = $docs->filter(fn ($doc) => ! Storage::disk('local')->exists($doc->storage_path))->pluck('original_filename')->all();
        if ($docs->isEmpty()) {
            $missingDocuments[] = 'No source documents uploaded.';
        }
        if ($missingDocuments) {
            $reasons[] = 'Required source documents are missing.';
        }
        $summary = DB::table('report_summary_revisions')->where('reporting_period_id', $p->id)->orderByDesc('revision')->first();
        $fingerprint = $this->summaries->fingerprint($p);
        $commentary = $summary && $summary->source_fingerprint === $fingerprint && DB::table('report_summary_approvals')->where('summary_revision_id', $summary->id)->where('fingerprint', $fingerprint)->exists();
        if (! $commentary) {
            $reasons[] = 'The current source-bound executive commentary requires explicit approval.';
        }
        $sections = $summary ? json_decode($summary->sections, true) : [];
        foreach (config('delivery.required_sections') as $key) {
            if (! trim($sections[$key] ?? '')) {
                $reasons[] = 'Required commentary is missing: '.$key;
            }
        }
        $validation = app(EvidenceValidation::class)->current($p);
        $manager = app(ManagerReportApproval::class)->context($p, $user, $validation);
        if ($validation && ! $manager['approved']) {
            $reasons[] = 'The entire current report requires final manager approval.';
        }
        $counts = NormalizedRecord::where('reporting_period_id', $p->id)->where('active', true)->selectRaw('review_status, count(*) as total')->groupBy('review_status')->pluck('total', 'review_status');

        return ['validation' => $validation ? array_diff_key($validation, ['records' => true]) : null, 'manager_approval' => $manager, 'eligible' => ! $reasons, 'reasons' => array_values(array_unique($reasons)), 'counts' => ['total' => $counts->sum(), 'approved' => $counts['approved'] ?? 0, 'pending' => $counts['pending'] ?? 0, 'rejected' => $counts['rejected'] ?? 0, 'business_records' => $d['coverage']['business_records'], 'human_verified' => $d['coverage']['human_verified']],
            'blocking_warnings' => array_values(array_filter($d['warnings'], fn ($w) => $w['blocking'] && ! $w['resolved_for_included_records'])), 'missing_documents' => $missingDocuments, 'missing_sections' => $missingSections, 'missing_metrics' => $missingMetrics,
            'commentary_approved' => (bool) $commentary, 'summary_revision' => $summary?->revision ?? 0, 'report_revision' => $p->version, 'status' => $p->status];
    }

    public function assertReady(ReportingPeriod $p, User $user): void
    {
        $r = $this->readiness($p, $user, true);
        abort_unless($r['eligible'], 409, implode(' ', $r['reasons']));
    }

    public function event(ReportingPeriod $p, ?string $publication, ?User $actor, string $action, array $metadata = []): void
    {
        // Never persist request URLs, headers, share tokens or token hashes in audit metadata.
        DB::table('report_delivery_events')->insert(['id' => (string) Str::ulid(), 'reporting_period_id' => $p->id, 'publication_id' => $publication, 'actor_id' => $actor?->id, 'action' => $action, 'metadata' => json_encode($metadata), 'created_at' => now()]);
    }

    public function image(?string $path): ?string
    {
        if (! $path || ! Storage::disk('local')->exists($path) || Storage::disk('local')->size($path) > 12 * 1024 * 1024) {
            return null;
        }
        $bytes = Storage::disk('local')->get($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return in_array($mime, ['image/png', 'image/jpeg', 'image/webp']) ? 'data:'.$mime.';base64,'.base64_encode($bytes) : null;
    }

    public function snapshot(ReportingPeriod $p, User $user, bool $draft, bool $includeUncertain = false): array
    {
        $d = $this->dashboard->build($p, $user, $draft || $includeUncertain);
        $allowed = ['report_metrics' => ['metric_key', 'label', 'value', 'unit', 'definition', 'granularity', 'source_start_date', 'source_end_date'], 'traffic_channels' => ['channel', 'total_users', 'new_users'], 'device_metrics' => ['device', 'clicks', 'impressions'], 'query_metrics' => ['query', 'clicks', 'impressions', 'ctr', 'position'], 'landing_page_metrics' => ['url', 'clicks', 'impressions', 'ctr'], 'keyword_rankings' => ['alternative_labels', 'keyword', 'ranking_label', 'category', 'ranking_date', 'search_location', 'group', 'page_group', 'exact_position'], 'seo_activities' => ['category', 'url', 'title', 'description', 'status', 'activity_date'], 'ai_visibility_evidence' => ['query', 'platform', 'brand_appearance'], 'event_metrics' => ['event_name', 'count']];
        $documents = $p->documents()->get()->keyBy('id');
        $assetIds = array_filter(array_column(array_column($d['records'], 'data'), 'asset_id'));
        $assets = DB::table('document_assets')->whereIn('id', $assetIds)->whereIn('document_id', $documents->keys())->get()->keyBy('id');
        $records = [];
        $run = NormalizationRun::where('reporting_period_id', $p->id)->latest()->orderByDesc('id')->first();
        $dates = collect($run?->summary['classifications'] ?? [])->keyBy('document_id');
        $validation = app(EvidenceValidation::class)->current($p);
        $periodEvidence = $validation && $validation['current'] ? collect($validation['summary']['source_periods'] ?? [])->keyBy('document_id') : collect();
        foreach ($d['records'] as $r) {
            $sources = [];
            foreach ($r['sources'] as $s) {
                $doc = $documents->get($s['document_id']);
                $period = $periodEvidence->get($s['document_id']);
                $sourceRanges = $period ? ($period['basis'] === 'native_month_title' ? [] : $period['ranges']) : ($dates->get($s['document_id'])['source_dates'] ?? []);
                $sources[] = ['document' => $doc?->original_filename, 'location' => array_intersect_key($s['location'] ?? [], array_flip(['page', 'sheet', 'row', 'start_line', 'end_line', 'block', 'table'])), 'source_period' => $sourceRanges, 'declared_month' => $period && $period['basis'] === 'native_month_title' ? substr($period['ranges'][0]['start'] ?? '', 0, 7) : null];
            }
            $image = null;
            if ($r['kind'] === 'ai_visibility_evidence' && ! empty($r['data']['asset_id'])) {
                $asset = $assets->get($r['data']['asset_id']);
                $image = $this->image($asset?->storage_path);
            }
            $records[] = ['id' => $r['id'], 'kind' => $r['kind'], 'section_key' => self::SECTION_MAP[$r['section_key']] ?? $r['section_key'], 'data' => array_intersect_key($r['data'], array_flip($allowed[$r['kind']] ?? [])), 'sources' => $sources, 'image' => $image,
                ...(($draft || $includeUncertain) ? ['provisional' => $r['provisional'], 'validation_status' => $r['validation_status'], 'human_verified' => $r['human_verified'], 'issues' => $r['issues']] : []),
                ...($draft ? ['review_status' => $r['review_status']] : [])];
        }
        $summary = DB::table('report_summary_revisions')->where('reporting_period_id', $p->id)->orderByDesc('revision')->first();

        $summaryFingerprint = $summary ? $this->summaries->fingerprint($p) : null;
        $summaryState = ! $summary ? 'missing' : ($summary->source_fingerprint !== $summaryFingerprint ? 'outdated' : (DB::table('report_summary_approvals')->where('summary_revision_id', $summary->id)->where('fingerprint', $summaryFingerprint)->exists() ? 'approved' : 'pending_approval'));

        return ['schema' => 1, 'draft' => $draft, 'report' => $d['report'], 'project' => ['name' => $d['project']['name'], 'website' => $d['project']['website'], 'client_name' => $d['project']['client_name'], 'logo' => $this->image($p->project->client?->logo_path)],
            'records' => $records, 'kpis' => array_map(fn ($k) => array_intersect_key($k, array_flip(['metric_key', 'label', 'unit', 'value', 'conflict', 'provisional'])), $d['kpis']), 'summary' => $summary ? json_decode($summary->sections, true) : [],
            'summary_origin' => $summary?->origin, 'summary_state' => $summaryState, 'summary_revision' => $summary?->revision ?? 0, 'warnings' => $draft ? $d['warnings'] : [], 'generated_at' => now()->toIso8601String(), 'methodology' => 'Source-reported period totals only. Ranking page groups are not exact positions. Channel observations are not deduplicated unique users. Missing values are not estimated.'];
    }

    public function publish(ReportingPeriod $p, User $user, int $revision): ReportPublication
    {
        return DB::transaction(function () use ($p, $user, $revision) {
            $p = ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(WorkspaceAccess::class)->manageProject($user, $p->project), 403);
            abort_unless($p->status === 'approved' && $p->version === $revision, 409, 'Publication requires Approved status and the current report revision.');
            $this->assertReady($p, $user);
            $version = (int) ReportPublication::where('reporting_period_id', $p->id)->max('version') + 1;
            $snapshot = $this->snapshot($p, $user, false);
            $snapshot['publication_version'] = $version;
            $snapshot['report']['status'] = 'published';
            $pub = ReportPublication::create(['reporting_period_id' => $p->id, 'project_id' => $p->project_id, 'version' => $version, 'published_by' => $user->id, 'published_at' => now(), 'snapshot' => $snapshot, 'snapshot_hash' => hash('sha256', json_encode($snapshot))]);
            $p->update(['status' => 'published', 'workspace_archived_at' => null, 'version' => $p->version + 1, 'updated_by' => $user->id]);
            $this->event($p, $pub->id, $user, 'report.published', ['version' => $version, 'snapshot_hash' => $pub->snapshot_hash]);

            return $pub;
        }, 5);
    }

    public function assertAvailable(ReportPublication $pub): ReportingPeriod
    {
        $p = ReportingPeriod::findOrFail($pub->reporting_period_id);
        $actor = User::findOrFail($pub->published_by);
        abort_unless($p->project_id === $pub->project_id && $p->status === 'published' && $p->project->status === 'active' && $p->project->client?->status !== 'archived', 404);
        abort_unless(hash_equals($pub->snapshot_hash, hash('sha256', json_encode($pub->snapshot))), 404);
        if (($pub->snapshot['schema'] ?? 1) >= 2) {
            // Validated at publication; later Draft checks cannot alter an immutable snapshot.
            return $p;
        }
        // Fail closed on changed approval, verification, source availability or commentary.
        abort_unless($this->readiness($p, $actor)['eligible'], 404);

        return $p;
    }
}
