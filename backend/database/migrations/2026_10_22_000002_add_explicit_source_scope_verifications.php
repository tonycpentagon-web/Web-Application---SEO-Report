<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_source_scope_verifications', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('reporting_period_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('document_id')->constrained('report_documents')->cascadeOnDelete();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('fingerprint', 64);
            $t->text('reason');
            $t->timestamp('created_at');
            $t->index(['reporting_period_id', 'document_id'], 'source_scope_report_document_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_source_scope_verifications');
    }
};
