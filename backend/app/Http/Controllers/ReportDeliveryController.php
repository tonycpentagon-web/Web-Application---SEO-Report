<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateReportPdf;
use App\Models\ReportingPeriod;
use App\Models\ReportPdfExport;
use App\Models\ReportPublication;
use App\Services\DocumentAccess;
use App\Services\ReportDelivery;
use App\Services\ReportPdfPresentation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReportDeliveryController extends Controller
{
    public function __construct(public DocumentAccess $access, public ReportDelivery $delivery) {}

    private function report(Request $r, string $project, string $report, bool $manage = false): ReportingPeriod
    {
        $p = $this->access->report($r->user(), $project, $report);
        abort_unless($manage ? $this->access->workspace->manageProject($r->user(), $p->project) : $this->access->workspace->workReports($r->user(), $p->project), 403);

        return $p;
    }

    public function index(Request $r, string $project, string $report)
    {
        $p = $this->report($r, $project, $report);
        $pubs = ReportPublication::where('reporting_period_id', $p->id)->orderByDesc('version')->get(['id', 'version', 'published_by', 'published_at', 'snapshot_hash']);

        $readiness = $this->delivery->readiness($p, $r->user());
        return response()->json(['data' => ['checklist' => app(\App\Services\ReportReviewChecklist::class)->context($p, $readiness), 'report' => $p->only(['title', 'start_date', 'end_date']), 'readiness' => $readiness, 'can_invite_clients' => $this->access->workspace->administrator($r->user()), 'can_manage' => $this->access->workspace->manageProject($r->user(), $p->project),
            'publications' => $pubs, 'exports' => ReportPdfExport::where('reporting_period_id', $p->id)->latest()->limit(100)->get()->map(fn ($e) => [...$e->toArray(), 'generated_by_name' => DB::table('users')->where('id', $e->generated_by)->value('name')]),
            'links' => DB::table('report_share_links')->whereIn('publication_id', $pubs->pluck('id'))->orderByDesc('created_at')->get(['id', 'publication_id', 'label', 'expires_at', 'revoked_at', 'allow_download', 'access_mode', 'created_at']),
            'viewers' => $p->viewers()->get(['users.id', 'users.name', 'users.email', 'users.is_active']),
            'available_viewers' => $this->access->workspace->manageProject($r->user(), $p->project)
                ? $this->access->workspace->assignable($r->user())->where('role', 'client_viewer')->orderBy('name')->get(['id', 'name', 'email']) : [],
            'events' => DB::table('report_delivery_events')->where('reporting_period_id', $p->id)->orderByDesc('created_at')->limit(100)->get()]]);
    }

    public function reviewChecks(Request $r, string $project, string $report)
    {
        $p = $this->report($r, $project, $report, true);
        $v = $r->validate(['confirmed' => 'required|accepted', 'version' => 'required|integer|min:1', 'source_fingerprint' => 'required|string|size:64', 'summary_revision' => 'required|integer|min:0', 'dashboard_reviewed' => 'required|boolean', 'pdf_export_id' => 'nullable|string']);
        DB::transaction(function () use ($r, $p, $v) {
            $locked = ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $v['version'], 409, 'This report changed. Reload before recording review.');
            $this->delivery->dashboard->reviews->context($locked, true);
            $readiness = $this->delivery->readiness($locked, $r->user());
            $context = app(\App\Services\ReportReviewChecklist::class)->context($locked, $readiness);
            abort_unless(hash_equals($context['source_fingerprint'], $v['source_fingerprint']) && $context['summary_revision'] === $v['summary_revision'], 409, 'Sources or commentary changed. Review the current report again.');
            abort_if(!empty($v['pdf_export_id']) && ! $context['reviewable_pdfs']->contains('id', $v['pdf_export_id']), 409, 'Choose a current complete client PDF. Generate a new PDF if the sources or commentary changed.');
            $this->delivery->event($locked, null, $r->user(), 'review.visual_checked', ['source_fingerprint' => $context['source_fingerprint'], 'summary_revision' => $context['summary_revision'], 'dashboard_reviewed' => $v['dashboard_reviewed'], 'pdf_export_id' => $v['pdf_export_id'] ?? null]);
        }, 5);

        return response()->json(['message' => 'Visual review recorded. Detailed approvals and report status are unchanged.']);
    }

    public function approveSummary(Request $r, string $project, string $report)
    {
        $p = $this->report($r, $project, $report, true);
        $v = $r->validate(['confirmed' => 'required|accepted', 'revision' => 'required|integer|min:1', 'reason' => 'required|string|min:10|max:2000']);
        DB::transaction(function () use ($p, $r, $v) {
            $p = ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless($p->status === 'draft' && $p->project->status === 'active' && $p->project->client?->status !== 'archived', 409, 'Commentary approval requires an active Draft report.');
            $s = DB::table('report_summary_revisions')->where('reporting_period_id', $p->id)->orderByDesc('revision')->first();
            $fp = $this->delivery->summaries->fingerprint($p);
            abort_unless($s && $s->revision === $v['revision'] && hash_equals($s->source_fingerprint, $fp), 409, 'Save current source-bound commentary before approving.');
            $sections = json_decode($s->sections, true);
            foreach (config('delivery.required_sections') as $key) {
                abort_unless(trim($sections[$key] ?? ''), 409, 'Missing required commentary: '.$key);
            }
            DB::table('report_summary_approvals')->insertOrIgnore(['id' => (string) Str::ulid(), 'summary_revision_id' => $s->id, 'fingerprint' => $fp, 'actor_id' => $r->user()->id, 'reason' => $v['reason'], 'created_at' => now(), 'updated_at' => now()]);
            $this->delivery->event($p, null, $r->user(), 'commentary.approved', ['revision' => $s->revision]);
        }, 5);

        return response()->json(['message' => 'Current commentary approved.']);
    }

    public function publish(Request $r, string $project, string $report)
    {
        $p = $this->report($r, $project, $report, true);
        $v = $r->validate(['confirmed' => 'required|accepted', 'version' => 'required|integer|min:1']);
        $pub = $this->delivery->publish($p, $r->user(), $v['version']);
        $export = $this->queueExport($p, $r, $pub);

        return response()->json(['data' => ['id' => $pub->id, 'version' => $pub->version, 'export_id' => $export->id]], 201);
    }

    public function publication(Request $r, string $project, string $report, string $publication)
    {
        $p = $this->report($r, $project, $report);
        $pub = ReportPublication::where('reporting_period_id', $p->id)->findOrFail($publication);

        return response()->json(['data' => $pub->snapshot]);
    }

    public function export(Request $r, string $project, string $report)
    {
        $p = $this->report($r, $project, $report);
        $v = $r->validate(['type' => 'required|in:draft,published', 'publication_id' => 'nullable|string', 'profile' => 'sometimes|in:client_preview,internal_audit', 'sections' => 'sometimes|array|min:1|max:9', 'sections.*' => 'required|distinct|in:'.implode(',', ReportDelivery::SECTIONS)]);
        abort_if($v['type'] === 'published' && ($v['profile'] ?? 'client_preview') === 'internal_audit', 422, 'Internal Audit Export is available only as a private Draft export.');
        $pub = $v['type'] === 'published' ? ReportPublication::where('reporting_period_id', $p->id)->findOrFail($v['publication_id'] ?? '') : null;

        return response()->json(['data' => $this->queueExport($p, $r, $pub)], 202);
    }

    private function queueExport(ReportingPeriod $p, Request $r, ?ReportPublication $pub): ReportPdfExport
    {
        $e = DB::transaction(function () use ($p, $r, $pub) {
            $p = ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $this->delivery->dashboard->reviews->context($p, true);
            $profile = $pub ? 'client_preview' : $r->input('profile', 'client_preview');
            $payload = $pub?->snapshot ?? app(ReportPdfPresentation::class)->capture($p, $r->user(), $profile);
            $e = ReportPdfExport::create(['profile' => $profile, 'reporting_period_id' => $p->id, 'publication_id' => $pub?->id, 'generated_by' => $r->user()->id, 'type' => $pub ? 'published' : 'draft', 'payload' => $payload, 'sections' => $profile === 'internal_audit' ? ReportDelivery::SECTIONS : array_values(array_intersect(ReportDelivery::SECTIONS, $r->input('sections', ReportDelivery::SECTIONS)))]);
            $this->delivery->event($p, $pub?->id, $r->user(), 'pdf.requested', ['export_id' => $e->id, 'type' => $e->type, 'profile' => $profile, 'source_fingerprint' => $this->delivery->summaries->fingerprint($p), 'summary_revision' => $payload['summary_revision'] ?? 0]);
            GenerateReportPdf::dispatch($e->id)->afterCommit();

            return $e;
        }, 5);

        return $e;
    }

    public function retry(Request $r, string $project, string $report, string $export)
    {
        $p = $this->report($r, $project, $report);
        $e = ReportPdfExport::where('reporting_period_id', $p->id)->findOrFail($export);
        DB::transaction(function () use ($e, $r, $p) {
            $locked = ReportPdfExport::whereKey($e->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'failed', 409, 'Only failed exports can be retried.');
            $locked->update(['status' => 'queued', 'progress' => 0, 'error' => null]);
            $this->delivery->event($p, $locked->publication_id, $r->user(), 'pdf.retried', ['export_id' => $locked->id]);
            GenerateReportPdf::dispatch($locked->id)->afterCommit();
        }, 5);

        return response()->json(['message' => 'Export queued again.']);
    }

    public function download(Request $r, string $project, string $report, string $export)
    {
        $p = $this->report($r, $project, $report);
        $e = ReportPdfExport::where('reporting_period_id', $p->id)->findOrFail($export);
        $response = $this->file($e);
        $this->delivery->event($p, $e->publication_id, $r->user(), 'pdf.downloaded', ['export_id' => $e->id]);

        return $response;
    }

    public function file(ReportPdfExport $e)
    {
        abort_unless($e->status === 'completed' && $e->storage_path && Storage::disk('local')->exists($e->storage_path), 404, 'The PDF is not available.');

        return Storage::disk('local')->download($e->storage_path, 'Pentagon-'.$e->type.'-'.$e->id.'.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer']);
    }

    public function share(Request $r, string $project, string $report)
    {
        $p = $this->report($r, $project, $report, true);
        $v = $r->validate(['access_mode'=>'sometimes|in:authenticated,public', 'public_confirmed'=>'exclude_unless:access_mode,public|required|accepted', 'publication_id' => 'required|string', 'label' => 'required|string|max:150', 'user_id' => 'sometimes|integer', 'expires_at' => 'nullable|date|after:now|before:+1 year', 'allow_download' => 'required|boolean']);
        // Omitting a custom date uses a bounded default; existing expiry checks remain enforced.
        $v['expires_at'] = $v['expires_at'] ?? now()->addDays(30)->toIso8601String();
        $token = bin2hex(random_bytes(32));
        $id = (string) Str::ulid();
        DB::transaction(function () use ($p, $r, $v, $token, $id) {
            $p = ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $pub = ReportPublication::where('reporting_period_id', $p->id)->findOrFail($v['publication_id']);
            $this->delivery->assertAvailable($pub);
            if (($v['access_mode']??'authenticated')==='public') { abort_if(isset($v['user_id']),422,'Public links do not assign client accounts.'); }
            if (isset($v['user_id'])) {
                abort_unless($this->access->workspace->assignable($r->user())->where('role', 'client_viewer')->whereKey($v['user_id'])->exists(), 422, 'Select an active Client Viewer from your permitted organization.');
                $p->viewers()->syncWithoutDetaching([$v['user_id'] => ['shared_by' => $r->user()->id, 'expires_at' => Carbon::parse($v['expires_at']), 'allow_download' => $v['allow_download']]]);
                $this->delivery->event($p, $pub->id, $r->user(), 'viewer.access_updated', ['user_id' => $v['user_id'], 'expires_at' => $v['expires_at'], 'allow_download' => $v['allow_download']]);
            }
            DB::table('report_share_links')->insert(['id' => $id, 'publication_id' => $pub->id, 'token_hash' => hash('sha256', $token), 'encrypted_token'=>\Illuminate\Support\Facades\Crypt::encryptString($token), 'label' => $v['label'], 'expires_at' => Carbon::parse($v['expires_at']), 'allow_download' => $v['allow_download'], 'access_mode'=>$v['access_mode']??'authenticated', 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            if(($v['access_mode']??'authenticated')==='public')app(\App\Services\ReportWorkspace::class)->file($p,$r->user());
            $this->delivery->event($p, $pub->id, $r->user(), 'share.created', ['access_mode'=>$v['access_mode']??'authenticated','share_id' => $id, 'expires_at' => $v['expires_at']]);
        }, 5);

        return response()->json(['data' => ['id' => $id, 'url' => rtrim(config('insight.frontend_url'), '/').(($v['access_mode']??'authenticated')==='public'?'/public-report#':'/shared-report#').$token, 'expires_at' => $v['expires_at']]], 201)->header('Cache-Control', 'no-store');
    }

    public function copyLink(Request $r,string $project,string $report,string $share)
    {
        $p=$this->report($r,$project,$report);
        $link=DB::table('report_share_links')->whereIn('publication_id',ReportPublication::where('reporting_period_id',$p->id)->select('id'))->where('access_mode','public')->whereNull('revoked_at')->where('expires_at','>',now())->where('id',$share)->first();
        abort_unless($link,404);
        $this->delivery->assertAvailable(ReportPublication::findOrFail($link->publication_id));
        abort_unless($link->encrypted_token,409,'This older link was stored only as a hash. Its existing URL still works; create an additional public link in Sharing to enable copying here.');
        try{$token=\Illuminate\Support\Facades\Crypt::decryptString($link->encrypted_token);}catch(\Illuminate\Contracts\Encryption\DecryptException $e){abort(409,'The stored link cannot be decrypted with this installation key. Use Sharing to create an additional link.');}
        abort_unless(hash_equals($link->token_hash,hash('sha256',$token)),409,'The stored link could not be verified.');
        return response()->json(['url'=>rtrim(config('insight.frontend_url'),'/').'/public-report#'.$token])->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');
    }

    public function revokeViewer(Request $r, string $project, string $report, string $viewer)
    {
        $p = $this->report($r, $project, $report, true);
        $r->validate(['confirmed' => 'required|accepted']);
        DB::transaction(function () use ($r, $p, $viewer) {
            $locked = ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->viewers()->where('users.id', $viewer)->exists(), 404);
            $locked->viewers()->detach($viewer);
            $this->delivery->event($locked, null, $r->user(), 'viewer.revoked', ['user_id' => (int) $viewer]);
        }, 5);

        return response()->json(['message' => 'Client access to this report revoked.']);
    }

    public function revoke(Request $r, string $project, string $report, string $share)
    {
        $p = $this->report($r, $project, $report);
        $r->validate(['confirmed' => 'required|accepted']);
        $link = DB::table('report_share_links')->whereIn('publication_id', ReportPublication::where('reporting_period_id', $p->id)->select('id'))->where('id', $share)->first();
        abort_unless($link, 404);
        DB::table('report_share_links')->where('id', $link->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        $this->delivery->event($p, $link->publication_id, $r->user(), 'share.revoked', ['share_id' => $link->id]);

        return response()->json(['message' => 'Share link revoked.']);
    }
}
