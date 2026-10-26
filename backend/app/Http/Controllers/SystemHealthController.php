<?php

namespace App\Http\Controllers;

use App\Services\SystemHealth;
use App\Services\WorkspaceAccess;
use Illuminate\Http\Request;

class SystemHealthController extends Controller
{
    public function show(Request $r, SystemHealth $health, WorkspaceAccess $access)
    {
        abort_unless($access->super($r->user()), 403);

        return response()->json(['data' => $health->snapshot(true)]);
    }

    public function probe(Request $r, SystemHealth $health, WorkspaceAccess $access)
    {
        abort_unless($access->super($r->user()), 403);
        $health->probe();

        return response()->json(['data' => $health->snapshot(true)]);
    }
}
