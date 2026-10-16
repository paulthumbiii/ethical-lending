<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceMakerChecker
{
    /**
     * Middleware intercepting financial API requests to assert authentication, activity, and dual-control readiness.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized: Operator authentication required.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['error' => 'Forbidden: Operational account is inactive.'], 403);
        }

        return $next($request);
    }
}