<?php

use App\Http\Controllers\Api\SeedController;
use Illuminate\Support\Facades\Route;

// Token-gated seed endpoint — the simulators call this on startup to re-hydrate
// their in-memory state from Laravel (source of truth).
Route::middleware('sim.token')
    ->get('/seed', [SeedController::class, 'all']);
