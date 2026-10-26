<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_summary_approvals', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('summary_revision_id')->constrained('report_summary_revisions')->restrictOnDelete();
            $t->string('fingerprint', 64);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->timestamps();
            $t->unique(['summary_revision_id', 'fingerprint']);
        });
        Schema::create('report_publications', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('reporting_period_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('published_at');
            $t->longText('snapshot');
            $t->string('snapshot_hash', 64);
            $t->unique(['reporting_period_id', 'version']);
        });
        Schema::create('report_share_links', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('publication_id')->constrained('report_publications')->restrictOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->string('label', 150);
            $t->timestamp('expires_at')->index();
            $t->timestamp('revoked_at')->nullable();
            $t->boolean('allow_download')->default(false);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::table('report_shares', function (Blueprint $t) {
            $t->timestamp('expires_at')->nullable()->index();
            $t->boolean('allow_download')->default(false);
        });
        Schema::create('report_pdf_exports', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('reporting_period_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('publication_id')->nullable()->constrained('report_publications')->restrictOnDelete();
            $t->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $t->string('type', 16);
            $t->string('status', 16)->default('queued')->index();
            $t->unsignedTinyInteger('progress')->default(0);
            $t->longText('payload');
            $t->text('sections');
            $t->string('storage_path')->nullable();
            $t->unsignedBigInteger('file_size')->nullable();
            $t->string('checksum', 64)->nullable();
            $t->text('error')->nullable();
            $t->timestamp('generated_at')->nullable();
            $t->timestamps();
            $t->index(['reporting_period_id', 'created_at']);
        });
        Schema::create('report_delivery_events', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('reporting_period_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('publication_id')->nullable()->constrained('report_publications')->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 64);
            $t->text('metadata');
            $t->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Publication and delivery history is retained. Use additive migrations.');
    }
};
