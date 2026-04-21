<?php

namespace App\Http\Controllers\Api;

use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeedController
{
    /**
     * Called by both simulators on startup to re-hydrate their in-memory state.
     * Protected by the `sim.token` middleware (shared secret in .env).
     */
    public function all(Request $request): JsonResponse
    {
        $devices = Device::with(['eimAssociations', 'preloadedProfiles'])
            ->where('enabled', true)
            ->get();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'count' => $devices->count(),
            'devices' => $devices->map->toSimulatorPayload()->values()->all(),
        ]);
    }
}
