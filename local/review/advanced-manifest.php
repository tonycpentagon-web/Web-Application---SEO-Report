<?php
require __DIR__.'/../../backend/vendor/autoload.php';$app=require __DIR__.'/../../backend/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$run=App\Models\NormalizationRun::where('reporting_period_id','01m5h5pm1xacszarrdcgxwbtnp')->latest()->orderByDesc('id')->firstOrFail();file_put_contents(__DIR__.'/advanced-manifest.json',json_encode(app(App\Services\ReportNormalization::class)->manifest($run)));echo 'Current extracted source manifest exported; no report records changed.';
