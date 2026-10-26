<?php

use App\Services\LegacyClientBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role', 24)->default('seo_executive')->index();
            $t->foreignId('managed_by')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::table('users')->where('is_super_admin', true)->update(['role' => 'super_admin']);
        Schema::create('clients', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('identity_key', 64);
            $t->string('name', 150);
            $t->string('company_name', 150)->nullable();
            $t->string('website', 2048)->nullable();
            $t->string('contact_email', 254)->nullable();
            $t->string('contact_phone', 40)->nullable();
            $t->string('country', 100)->nullable();
            $t->string('logo_path')->nullable();
            $t->string('brand_color', 7)->default('#087e98');
            $t->string('status', 16)->default('active');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'identity_key']);
            $t->index(['status', 'name']);
        });
        Schema::table('projects', function (Blueprint $t) {
            $t->foreignUlid('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $t->string('reporting_frequency', 16)->default('monthly');
            $t->date('start_date')->nullable();
        });
        Schema::create('client_memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('client_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['client_id', 'user_id']);
        });
        Schema::create('project_memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('assignment_role', 16)->default('member');
            $t->timestamps();
            $t->unique(['project_id', 'user_id']);
        });
        Schema::create('reporting_periods', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $t->string('title', 180);
            $t->string('reporting_month', 7);
            $t->string('kind', 12)->default('monthly');
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 20)->default('draft');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['project_id', 'start_date', 'end_date'], 'report_period_range_unique');
            $t->index(['project_id', 'status', 'reporting_month']);
        });
        Schema::create('report_shares', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('reporting_period_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('shared_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['reporting_period_id', 'user_id']);
        });
        Schema::create('project_activity_logs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('reporting_period_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 80);
            $t->json('properties')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['project_id', 'created_at']);
        });
        app(LegacyClientBackfill::class)->run();
    }

    public function down(): void
    {
        // This data-bearing migration is intentionally forward-only. Never drop client/report data on rollback.
        throw new RuntimeException('Stage 2 preserves business data. Restore a verified backup or write an additive corrective migration.');
    }
};
