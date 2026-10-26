<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_validation_runs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('reporting_period_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('normalization_run_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('rules_version');
            $t->string('fingerprint', 64);
            $t->json('before_summary');
            $t->json('summary');
            $t->timestamps();
            $t->index(['reporting_period_id', 'created_at']);
        });
        Schema::create('record_validation_results', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('validation_run_id')->constrained('report_validation_runs')->cascadeOnDelete();
            $t->foreignUlid('normalized_record_id')->constrained()->cascadeOnDelete();
            $t->string('status', 24);
            $t->json('issues');
            $t->json('checks');
            $t->json('evidence');
            $t->unique(['validation_run_id', 'normalized_record_id'], 'record_validation_run_record_unique');
        });
        Schema::create('report_manager_approvals', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('reporting_period_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('validation_run_id')->constrained('report_validation_runs');
            $t->foreignId('actor_id')->constrained('users');
            $t->unsignedInteger('report_revision');
            $t->unsignedInteger('summary_revision');
            $t->string('fingerprint', 64);
            $t->text('reason');
            $t->timestamp('created_at');
            $t->index(['reporting_period_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_manager_approvals');
        Schema::dropIfExists('record_validation_results');
        Schema::dropIfExists('report_validation_runs');
    }
};
