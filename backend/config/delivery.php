<?php

return [
    'node' => env('PDF_NODE_BINARY', 'node'),
    'chromium' => env('PDF_CHROMIUM_PATH'),
    'timeout' => (int) env('PDF_TIMEOUT', 180),
    'draft_retention_days' => (int) env('PDF_DRAFT_RETENTION_DAYS', 30),
    'required_metrics' => ['ga4.total_users', 'gsc.clicks', 'gsc.impressions'],
    'required_sections' => ['website_traffic', 'organic_search', 'keywords', 'seo_work', 'ai_visibility', 'key_events'],
];
