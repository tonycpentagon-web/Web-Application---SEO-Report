<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('normalization_runs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('reporting_period_id');
            $t->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $t->foreign(['reporting_period_id', 'project_id'])->references(['id', 'project_id'])->on('reporting_periods')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('status', 24)->default('queued');
            $t->string('fingerprint', 64);
            $t->string('processor_version', 80)->nullable();
            $t->longText('input_snapshot');
            $t->longText('summary')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['reporting_period_id', 'created_at']);
        });
        Schema::create('report_sections', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('reporting_period_id')->constrained()->restrictOnDelete();
            $t->string('section_key', 40);
            $t->string('label');
            $t->unsignedInteger('record_count')->default(0);
            $t->timestamps();
            $t->unique(['reporting_period_id', 'section_key']);
        });
        Schema::create('normalized_records', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('reporting_period_id');
            $t->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $t->foreign(['reporting_period_id', 'project_id'])->references(['id', 'project_id'])->on('reporting_periods')->restrictOnDelete();
            $t->foreignUlid('normalization_run_id')->constrained()->restrictOnDelete();
            $t->string('kind', 40);
            $t->string('section_key', 40);
            $t->string('source_key', 64);
            $t->longText('source_data');
            $t->longText('raw_value');
            $t->longText('manual_override')->nullable();
            $t->string('source_hash', 64);
            $t->string('confidence', 24);
            $t->string('review_status', 24)->default('pending');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['reporting_period_id', 'kind', 'source_key'], 'report_normalized_source_unique');
            $t->index(['reporting_period_id', 'section_key', 'active']);
        });
        Schema::create('metric_source_references', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('normalized_record_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('normalization_run_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('document_id')->constrained('report_documents')->restrictOnDelete();
            $t->foreignUlid('extraction_run_id')->constrained('document_extraction_runs')->restrictOnDelete();
            $t->foreignUlid('content_id')->nullable()->constrained('document_extracted_content')->restrictOnDelete();
            $t->foreignUlid('asset_id')->nullable()->constrained('document_assets')->restrictOnDelete();
            $t->string('reference_key', 64);
            $t->longText('location');
            $t->longText('quote')->nullable();
            $t->longText('raw_value')->nullable();
            $t->timestamps();
            $t->unique(['normalized_record_id', 'reference_key'], 'normalized_reference_unique');
        });
        Schema::create('validation_warnings', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('normalization_run_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('normalized_record_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUlid('document_id')->nullable()->constrained('report_documents')->restrictOnDelete();
            $t->string('code', 60);
            $t->string('severity', 16);
            $t->text('message');
            $t->longText('source');
            $t->timestamps();
            $t->index(['normalization_run_id', 'code']);
        });
        Schema::create('record_corrections', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('normalized_record_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->longText('before_value');
            $t->longText('after_value');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Normalization decisions and evidence are retained. Use additive corrective migrations.');
    }
};
