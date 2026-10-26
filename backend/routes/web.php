<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['name' => 'Pentagon Insight Flow API', 'stage' => 1]));
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:passwords');
Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:passwords');
Route::middleware(['auth:web', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/password', [AuthController::class, 'password'])->middleware('throttle:passwords');
});

Route::get('/up', fn () => response()->json(['status' => 'ok']));
