<?php

namespace App\Http\Controllers;

use App\Services\DocumentAccess;
use App\Services\ReportDashboard;
use Illuminate\Http\Request;

class ReportDashboardController extends Controller
{
    public function __invoke(Request $r, string $project, string $report, DocumentAccess $access, ReportDashboard $dashboard)
    {
        $p = $access->report($r->user(), $project, $report);
        $r->validate(['include_provisional' => 'nullable|boolean']);

        return response()->json(['data' => $dashboard->build($p, $r->user(), $r->boolean('include_provisional', true))])->header('Cache-Control', 'private, no-store');
    }
}
