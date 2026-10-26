<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reporting_periods', fn (Blueprint $t) => $t->unique(['id', 'project_id'], 'reports_id_project_unique'));
        Schema::create('report_documents', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('reporting_period_id');
            $t->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $t->foreign(['reporting_period_id', 'project_id'])->references(['id', 'project_id'])->on('reporting_periods')->restrictOnDelete();
            $t->text('original_filename');
            $t->string('stored_filename');
            $t->string('extension', 8);
            $t->string('mime_type', 150);
            $t->unsignedBigInteger('file_size');
            $t->string('checksum', 64);
            $t->string('active_checksum', 64)->nullable();
            $t->string('storage_path');
            $t->uuid('batch_id')->nullable();
            $t->string('upload_status', 24)->default('uploaded');
            $t->string('extraction_status', 32)->default('queued');
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['reporting_period_id', 'active_checksum'], 'active_report_document_checksum');
            $t->index(['project_id', 'extraction_status']);
            $t->index(['reporting_period_id', 'created_at']);
        });
        Schema::create('document_extraction_runs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('document_id')->constrained('report_documents')->restrictOnDelete();
            $t->string('status', 32)->default('queued');
            $t->string('processor_version', 80)->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('heartbeat_at')->nullable();
            $t->string('stage', 255)->default('Waiting for a document queue worker');
            $t->unsignedInteger('processed_units')->default(0);
            $t->unsignedInteger('total_units')->nullable();
            $t->text('error_message')->nullable();
            $t->longText('warnings')->nullable();
            $t->longText('events')->nullable();
            $t->longText('statistics')->nullable();
            $t->longText('raw_json')->nullable();
            $t->string('raw_path')->nullable();
            $t->timestamps();
            $t->index(['document_id', 'created_at']);
            $t->index(['status', 'heartbeat_at']);
        });
        Schema::table('report_documents', fn (Blueprint $t) => $t->foreignUlid('current_run_id')->nullable()->constrained('document_extraction_runs')->restrictOnDelete());
        Schema::create('document_extracted_content', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('document_id')->constrained('report_documents')->restrictOnDelete();
            $t->foreignUlid('extraction_run_id')->constrained('document_extraction_runs')->restrictOnDelete();
            $t->unsignedInteger('ordinal');
            $t->string('content_type', 32);
            $t->unsignedInteger('page_number')->nullable();
            $t->string('worksheet_name')->nullable();
            $t->text('section_name')->nullable();
            $t->longText('original_text')->nullable();
            $t->longText('structured_json')->nullable();
            $t->longText('source_location')->nullable();
            $t->string('confidence_status', 32);
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['extraction_run_id', 'ordinal'], 'extraction_content_ordinal_unique');
            $t->index(['document_id', 'content_type']);
        });
        Schema::create('document_assets', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('document_id')->constrained('report_documents')->restrictOnDelete();
            $t->foreignUlid('extraction_run_id')->constrained('document_extraction_runs')->restrictOnDelete();
            $t->unsignedInteger('asset_index');
            $t->string('asset_type', 32);
            $t->unsignedInteger('source_page')->nullable();
            $t->string('worksheet_name')->nullable();
            $t->string('storage_path');
            $t->string('mime_type', 150);
            $t->unsignedBigInteger('file_size');
            $t->string('checksum', 64);
            $t->longText('metadata')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['extraction_run_id', 'asset_index'], 'extraction_asset_index_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Document evidence is retained. Use an additive corrective migration or a verified backup, never a destructive rollback.');
    }
};
