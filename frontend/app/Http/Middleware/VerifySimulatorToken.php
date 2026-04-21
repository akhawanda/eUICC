<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySimulatorToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('simulators.seed_token');
        $provided = $request->bearerToken() ?? $request->header('X-Sim-Token');

        if (! $expected || ! $provided || ! hash_equals($expected, $provided)) {
            abort(401, 'Invalid simulator token');
        }

        return $next($request);
    }
}
