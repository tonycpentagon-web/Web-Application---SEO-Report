<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['report_metrics', 'traffic_channels', 'device_metrics', 'landing_page_metrics', 'keyword_rankings', 'seo_activities', 'ai_visibility_evidence', 'event_metrics'] as $kind) {
            DB::statement('CREATE VIEW '.$kind." AS SELECT * FROM normalized_records WHERE kind = '".$kind."'");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Use an additive migration to change normalized projections.');
    }
};
