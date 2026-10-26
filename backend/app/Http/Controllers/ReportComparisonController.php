<?php

namespace App\Http\Controllers;

use App\Models\ReportPublication;
use App\Services\DocumentAccess;
use App\Services\ReportComparison;
use App\Services\ReportDelivery;
use App\Services\WorkspaceAccess;
use Illuminate\Http\Request;

class ReportComparisonController extends Controller
{
    public function __invoke(Request $r, string $project, string $report, WorkspaceAccess $access, ReportDelivery $delivery, ReportComparison $comparison)
    {
        $v = $r->validate(['with' => 'required|string|max:26|different:report']);
        abort_if($v['with'] === $report, 422, 'Choose a different reporting month.');
        $projectModel = $access->projects($r->user())->findOrFail($project);
        $snapshots = [];
        foreach ([$report, $v['with']] as $id) {
            $period = $access->reports($r->user(), $projectModel)->findOrFail($id);
            if ($access->role($r->user()) === 'client_viewer') {
                $grant = $period->viewers()->where('users.id', $r->user()->id)->first()?->pivot;
                abort_unless($grant && $grant->expires_at && now()->lt($grant->expires_at), 404);
                $publication = ReportPublication::where('reporting_period_id', $id)->orderByDesc('version')->firstOrFail();
                $delivery->assertAvailable($publication);
                $snapshots[] = $publication->snapshot;
            } else {
                $period = app(DocumentAccess::class)->report($r->user(), $project, $id);
                abort_if($delivery->dashboard->build($period, $r->user(), true)['stale'], 409, 'Normalize current sources before comparing.');
                $snapshots[] = $delivery->snapshot($period, $r->user(), true);
            }
        }

        return response()->json(['data' => $comparison->compare(...$snapshots)])->header('Cache-Control','private, no-store');
    }
}
