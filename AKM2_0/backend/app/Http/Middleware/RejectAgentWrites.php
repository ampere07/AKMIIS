<?php

namespace App\Http\Middleware;

use App\Support\AgentReferral;
use Closure;
use Illuminate\Http\Request;

/**
 * Agents have read-only access to the routes this guards: GET and HEAD pass,
 * anything that would change data is refused.
 */
class RejectAgentWrites
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->isMethodSafe() && AgentReferral::isAgent($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'Agents have read-only access',
            ], 403);
        }

        return $next($request);
    }
}
