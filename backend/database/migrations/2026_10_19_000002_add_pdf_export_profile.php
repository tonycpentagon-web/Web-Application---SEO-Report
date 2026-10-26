<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_pdf_exports', function (Blueprint $t) {
            $t->string('profile', 24)->nullable()->index();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve export profile history; use additive migrations.');
    }
};
