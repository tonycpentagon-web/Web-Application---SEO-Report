<?php

namespace App\Http\Controllers;

use App\Models\ReportingPeriod;
use App\Models\ReportPdfExport;
use App\Models\ReportPublication;
use App\Services\ReportDelivery;
use App\Services\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientReportController extends Controller
{
    public function __construct(public ReportDelivery $delivery, public WorkspaceAccess $access) {}

    public function shared(Request $r)
    {
        $token = $r->header('X-Report-Token', '');
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 404);
        $link = DB::table('report_share_links')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        abort_unless($link, 404);

        $pub = ReportPublication::findOrFail($link->publication_id);
        $period = ReportingPeriod::findOrFail($pub->reporting_period_id);
        $grant = $period->viewers()->where('users.id', $r->user()->id)->first()?->pivot;
        abort_unless($grant && $grant->expires_at && now()->lt($grant->expires_at), 404);

        return $this->respond($r, $pub, (bool) $link->allow_download && (bool) $grant->allow_download, ['share_id' => $link->id]);
    }

    public function publicShared(Request $r)
    {
        $token=$r->header('X-Report-Token','');
        abort_unless(preg_match('/^[a-f0-9]{64}$/D',$token),404);
        $link=DB::table('report_share_links')->where('token_hash',hash('sha256',$token))->where('access_mode','public')->whereNull('revoked_at')->where('expires_at','>',now())->first();
        abort_unless($link,404);
        return $this->respond($r,ReportPublication::findOrFail($link->publication_id),(bool)$link->allow_download,['share_id'=>$link->id,'access_mode'=>'public']);
    }

    public function index(Request $r)
    {
        abort_unless($this->access->role($r->user()) === 'client_viewer', 403);
        $r->validate(['page' => 'sometimes|integer|min:1']);
        $reports = ReportingPeriod::whereIn('project_id', $this->access->projects($r->user())->select('id'))
            ->where('status', 'published')->whereHas('viewers', fn ($q) => $q->where('users.id', $r->user()->id)->where('report_shares.expires_at', '>', now()))
            ->whereHas('project', fn ($q) => $q->where('status', 'active')->whereHas('client', fn ($c) => $c->where('status', '!=', 'archived')))
            ->whereIn('id', ReportPublication::select('reporting_period_id'))->with('project.client')->orderByDesc('start_date')->paginate(100);

        return $reports->through(function ($p) use ($r) {
            $pub = ReportPublication::where('reporting_period_id', $p->id)->latest('version')->first();
            $grant = $p->viewers()->where('users.id', $r->user()->id)->first()?->pivot;

            return ['id' => $p->id, 'project_id' => $p->project_id, 'title' => $p->title, 'start_date' => $p->start_date->format('Y-m-d'), 'end_date' => $p->end_date->format('Y-m-d'), 'client_name' => $p->project->client?->company_name ?? $p->project->name, 'project_name' => $p->project->name,
                'client_logo' => $pub?->snapshot['project']['logo'] ?? null, 'allow_download' => (bool) $grant?->allow_download,
                'pdf_ready' => ReportPdfExport::where('publication_id', $pub?->id)->where('type', 'published')->where('status', 'completed')->where(fn ($q) => $q->whereNull('profile')->orWhere('profile', 'client_preview'))->where('sections', json_encode(ReportDelivery::SECTIONS))->exists()];
        });
    }

    public function account(Request $r, string $project, string $report)
    {
        $p = $this->access->projects($r->user())->findOrFail($project);
        $period = $this->access->reports($r->user(), $p)->findOrFail($report);
        $grant = $period->viewers()->where('users.id', $r->user()->id)->first()?->pivot;
        abort_unless($grant && $grant->expires_at && now()->lt($grant->expires_at), 404);
        $pub = ReportPublication::where('reporting_period_id', $period->id)->orderByDesc('version')->firstOrFail();

        return $this->respond($r, $pub, (bool) $grant->allow_download);
    }

    private function respond(Request $r, ReportPublication $pub, bool $download, array $metadata = [])
    {
        $p = $this->delivery->assertAvailable($pub);
        if ($r->boolean('pdf')) {
            abort_unless($download, 403);
            // Client downloads are always the complete immutable publication, never Draft or partial exports.
            $e = ReportPdfExport::where('publication_id', $pub->id)->where('type', 'published')->where(fn ($q) => $q->whereNull('profile')->orWhere('profile', 'client_preview'))->where('status', 'completed')->where('sections', json_encode(ReportDelivery::SECTIONS))->latest()->firstOrFail();
            $this->delivery->event($p, $pub->id, $r->user(), 'client.pdf_downloaded', $metadata);

            return app(ReportDeliveryController::class)->file($e);
        }
        $this->delivery->event($p, $pub->id, $r->user(), 'client.viewed', $metadata);

        return response()->json(['data' => $pub->snapshot, 'allow_download' => $download, 'pdf_status' => ReportPdfExport::where('publication_id', $pub->id)->where('type', 'published')->latest()->value('status') ?? 'unavailable'])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }
}
