<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_verifications', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('normalized_record_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('evidence_hash', 64);
            $t->string('value_hash', 64);
            $t->string('context_hash', 64);
            $t->text('reason');
            $t->longText('resolutions');
            $t->timestamps();
        });
        Schema::create('bulk_review_requests', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('reporting_period_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key');
            $t->string('payload_hash', 64);
            $t->longText('response');
            $t->timestamps();
            $t->unique(['reporting_period_id', 'request_key'], 'bulk_review_request_unique');
        });
        Schema::table('record_corrections', function (Blueprint $t) {
            $t->string('approval_method', 24)->nullable();
            $t->longText('review_metadata')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Review and verification history must be retained. Use additive migrations.');
    }
};
