<?php

namespace App\Services;

use App\Models\ReportingPeriod;
use App\Models\ReportPdfExport;
use App\Models\ReportPublication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReportReviewChecklist
{
    public function context(ReportingPeriod $p, array $readiness): array
    {
        $fingerprint = app(ReportDelivery::class)->summaries->fingerprint($p);
        $requests = DB::table('report_delivery_events')->where('reporting_period_id', $p->id)->where('action', 'pdf.requested')
            ->where('metadata->source_fingerprint', $fingerprint)->where('metadata->summary_revision', $readiness['summary_revision'])->get();
        $exportIds = $requests->map(fn ($e) => json_decode($e->metadata, true)['export_id'])->all();
        $pdfs = ReportPdfExport::where('reporting_period_id', $p->id)->whereIn('id', $exportIds)->where('status', 'completed')->where('profile', 'client_preview')->where('sections', json_encode(ReportDelivery::SECTIONS))->latest()->get()->filter(fn ($e) => $e->storage_path && Storage::disk('local')->exists($e->storage_path));
        $event = DB::table('report_delivery_events')->where('reporting_period_id', $p->id)->where('action', 'review.visual_checked')
            ->where('metadata->source_fingerprint', $fingerprint)->where('metadata->summary_revision', $readiness['summary_revision'])->orderByDesc('id')->first();
        $review = $event ? json_decode($event->metadata, true) : [];
        $docs = $p->documents()->get();
        $processed = $docs->isNotEmpty() && $docs->every(fn ($d) => in_array($d->extraction_status, ['extracted', 'partially_extracted', 'needs_review'])) && ! $readiness['missing_documents'];
        $links=DB::table('report_share_links')->whereIn('publication_id', ReportPublication::where('reporting_period_id',$p->id)->select('id'))->whereNull('revoked_at')->where('expires_at','>',now());
        $access=$p->status==='published' && ((clone $links)->where('access_mode','public')->exists() || ((clone $links)->where('access_mode','authenticated')->exists() && $p->viewers()->where('users.is_active',true)->where('report_shares.expires_at','>',now())->exists()));
        $items = [
            ['key' => 'sources', 'label' => 'Source documents processed', 'complete' => $processed],
            ['key' => 'data', 'label' => 'Required report data present', 'complete' => ! $readiness['missing_sections'] && ! $readiness['missing_metrics'] && $readiness['counts']['business_records'] > 0],
            ['key' => 'conflicts', 'label' => 'Source validation and conflicts reviewed', 'complete' => $readiness['eligible']],
            ['key' => 'warnings', 'label' => 'Material extraction warnings addressed', 'complete' => $processed && ! $readiness['blocking_warnings']],
            ['key' => 'commentary', 'label' => 'Executive commentary approved', 'complete' => $readiness['commentary_approved']],
            ['key' => 'dashboard', 'label' => 'Dashboard visually reviewed', 'complete' => (bool) ($review['dashboard_reviewed'] ?? false)],
            ['key' => 'pdf', 'label' => 'Current complete client PDF reviewed', 'complete' => $pdfs->contains('id', $review['pdf_export_id'] ?? '')],
            ['key' => 'access', 'label' => 'Client access configured after publication', 'complete' => (bool) $access],
        ];

        return ['source_fingerprint' => $fingerprint, 'summary_revision' => $readiness['summary_revision'], 'items' => $items,
            'dashboard_reviewed' => (bool) ($review['dashboard_reviewed'] ?? false), 'pdf_export_id' => $review['pdf_export_id'] ?? null,
            'reviewable_pdfs' => $pdfs->map(fn ($e) => ['id' => $e->id, 'type' => $e->type, 'generated_at' => $e->generated_at])->values(),
            'note' => 'Visual checks record a human review of the current sources and commentary. Existing source verification, approval and publication safeguards remain independently enforced. Client access is configured after publication.'];
    }
}
