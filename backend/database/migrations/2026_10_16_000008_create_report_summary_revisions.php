<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_summary_revisions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('reporting_period_id');
            $t->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $t->foreign(['reporting_period_id', 'project_id'])->references(['id', 'project_id'])->on('reporting_periods')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->string('source_fingerprint', 64);
            $t->longText('sections');
            $t->text('reason');
            $t->timestamps();
            $t->unique(['reporting_period_id', 'revision'], 'report_summary_revision_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Summary revision history is retained. Use additive migrations.');
    }
};
