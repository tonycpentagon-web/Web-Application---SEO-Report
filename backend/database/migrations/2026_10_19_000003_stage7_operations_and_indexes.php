<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_heartbeats', function (Blueprint $t) {
            $t->string('worker_id', 64);
            $t->string('queue', 100);
            $t->timestamp('last_seen_at')->index();
            $t->string('state', 16);
            $t->primary(['worker_id', 'queue']);
        });
        Schema::table('normalized_records', fn (Blueprint $t) => $t->index(['reporting_period_id', 'active', 'kind', 'review_status'], 'records_report_review_lookup'));
        Schema::table('record_verifications', fn (Blueprint $t) => $t->index(['normalized_record_id', 'created_at'], 'verification_record_history'));
        Schema::table('report_delivery_events', fn (Blueprint $t) => $t->index(['reporting_period_id', 'created_at'], 'delivery_report_history'));
        Schema::table('report_pdf_exports', fn (Blueprint $t) => $t->index(['publication_id', 'type', 'status'], 'exports_publication_download'));
        Schema::table('validation_warnings', fn (Blueprint $t) => $t->index(['normalization_run_id', 'document_id'], 'warning_run_document'));
    }

    public function down(): void
    {
        throw new RuntimeException('Operational/history indexes are additive; do not roll back destructively.');
    }
};
