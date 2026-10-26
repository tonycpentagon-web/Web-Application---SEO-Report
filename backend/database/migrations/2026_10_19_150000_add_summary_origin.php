<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_summary_revisions', fn (Blueprint $table) => $table->string('origin')->default('manual'));
    }

    public function down(): void
    {
        Schema::table('report_summary_revisions', fn (Blueprint $table) => $table->dropColumn('origin'));
    }
};
