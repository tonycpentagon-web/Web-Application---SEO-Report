<?php

namespace App\Console\Commands;

use App\Http\Controllers\ReportDataController;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class NormalizeReportingPeriod extends Command
{
    protected $signature = 'reports:normalize {report : Existing reporting period ID} {--actor= : Active internal user ID for authorization and audit}';

    protected $description = 'Queue source-backed normalization for an existing Draft reporting period';

    public function handle(ReportDataController $controller): int
    {
        $period = ReportingPeriod::findOrFail($this->argument('report'));
        $actor = User::findOrFail($this->option('actor'));
        if (! $actor->is_active) {
            $this->error('The audit actor must be active.');

            return self::FAILURE;
        }
        $request = Request::create('/api/projects/'.$period->project_id.'/reports/'.$period->id.'/data/normalize', 'POST');
        $request->setUserResolver(fn () => $actor);
        $response = $controller->normalize($request, $period->project_id, $period->id);
        $this->line(json_encode($response->getData(true), JSON_PRETTY_PRINT));

        return $response->getStatusCode() === 202 ? self::SUCCESS : self::FAILURE;
    }
}
