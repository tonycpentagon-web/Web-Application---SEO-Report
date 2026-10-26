<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_generations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('reporting_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('queued');
            $table->string('stage')->default('Waiting for document extraction');
            $table->foreignUlid('normalization_run_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['reporting_period_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_generations');
    }
};
