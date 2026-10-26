<?php

namespace App\Services;

use App\Http\Controllers\ReportSummaryController;
use App\Models\ProjectActivityLog;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Deterministic source summaries. No external model, performance inference or approval. */
class DraftCommentary
{
    public function generate(ReportingPeriod $p, User $user, bool $replaceManual = false, bool $prepareUpdatedPublication = false): array
    {
        return DB::transaction(function () use ($p, $user, $replaceManual, $prepareUpdatedPublication) {
            $p = ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active && ($prepareUpdatedPublication ? app(DocumentAccess::class)->canPreparePublication($user, $p) : app(DocumentAccess::class)->canUpload($user, $p)), 409, 'Commentary generation requires an active Draft report.');
            $latest = DB::table('report_summary_revisions')->where('reporting_period_id', $p->id)->orderByDesc('revision')->first();
            $fingerprint = app(ReportSummaryController::class)->fingerprint($p);
            if ($latest && ((! $replaceManual && $latest->origin !== 'generated') || ($latest->origin === 'generated' && $latest->source_fingerprint === $fingerprint))) {
                return ['revision' => $latest->revision, 'preserved' => true, 'origin' => $latest->origin];
            }
            $d = app(ReportDashboard::class)->build($p, $user, true, true);
            abort_if($d['stale'], 409, 'Complete normalization before generating current commentary.');
            $metric = function (string $key) use ($d): string {
                $k = collect($d['kpis'])->firstWhere('metric_key', $key);

                return ! $k || $k['conflict'] || $k['value'] === null ? 'not reliably reported' : number_format($k['value'], is_float($k['value']) ? 2 : 0).($k['unit'] === 'percent' ? '%' : '');
            };
            $rows = collect($d['records']);
            $keywords = $rows->where('kind', 'keyword_rankings');
            $distribution = $keywords->countBy(fn ($r) => $r['data']['ranking_label'] ?? 'Not reported')->take(12)->map(fn ($n, $label) => $label.': '.$n)->implode('; ');
            $activities = $rows->where('kind', 'seo_activities');
            $categories = $activities->countBy(fn ($r) => $r['data']['category'] ?? 'unspecified')->take(12)->map(fn ($n, $label) => str_replace('_', ' ', $label).': '.$n)->implode('; ');
            $events = $rows->where('kind', 'event_metrics');
            $sections = [
                'website_traffic' => 'Reported users: '.$metric('ga4.total_users').'; new users: '.$metric('ga4.new_users').'; views: '.$metric('ga4.views').'. These are source-period totals; no prior-period growth or cause is inferred.',
                'organic_search' => 'Search Console clicks: '.$metric('gsc.clicks').'; impressions: '.$metric('gsc.impressions').'; reported CTR: '.$metric('gsc.ctr').'. Clicks and users retain different source definitions.',
                'keywords' => $keywords->count().' keyword observations are reported. Reported label counts (up to 12 labels): '.($distribution ?: 'Ranking distribution is not reported.').'. Page groups are not exact Google positions; no ranking improvement is inferred.',
                'seo_work' => $activities->count().' listed SEO activity observations are retained. Category counts (up to 12 categories): '.($categories ?: 'Activity categories are not reported.').'. Duplicate observations remain available; a submission URL is not proof of a live or indexed backlink.',
                'ai_visibility' => $rows->where('kind', 'ai_visibility_evidence')->count().' AI screenshot evidence observations are available. Screenshot evidence and recorded review decisions must be inspected before claiming a verified recommendation. No visibility score, traffic or AI ranking is inferred.',
                'key_events' => $events->isEmpty() ? 'Key Events source is missing or has not produced readable event observations. Upload the original event screenshot/report; no event total is estimated.' : $events->count().' event-name/count observations are reported. Source-reported total: '.$metric('events.reported_total').'. Event counts are not automatically verified leads or conversions.',
                'data_limitations' => $d['coverage']['provisional'].' of '.$d['coverage']['business_records'].' business observations remain provisional. '.$d['publication']['blocking_warning_count'].' unresolved blocking warnings remain. Missing metrics, source-period differences and unclassified source content require inspection. Uncertain evidence is labelled and disclosed when sharing; it is not human-verified.',
            ];
            $revision = ($latest?->revision ?? 0) + 1;
            DB::table('report_summary_revisions')->insert(['id' => (string) Str::ulid(), 'reporting_period_id' => $p->id, 'project_id' => $p->project_id, 'actor_id' => $user->id, 'revision' => $revision, 'source_fingerprint' => $fingerprint, 'sections' => json_encode($sections), 'origin' => 'generated', 'reason' => $replaceManual ? 'Explicit replacement with deterministic source-backed Draft commentary.' : 'Generated factual Draft commentary from current normalized source observations.', 'created_at' => now(), 'updated_at' => now()]);
            ProjectActivityLog::create(['project_id' => $p->project_id, 'reporting_period_id' => $p->id, 'actor_id' => $user->id, 'action' => 'report.summary_generated', 'properties' => ['revision' => $revision, 'origin' => 'generated', 'replaced_manual' => $replaceManual]]);

            return ['revision' => $revision, 'preserved' => false, 'origin' => 'generated'];
        }, 5);
    }
}
