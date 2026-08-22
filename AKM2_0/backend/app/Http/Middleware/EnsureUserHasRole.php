<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to one or more roles, e.g. ->middleware('role:super_admin').
 *
 * Arguments are slugs (see Role::SLUGS) or bare role IDs.
 *
 * Most report routes sit outside the `auth:sanctum` group, so an unauthenticated
 * caller reaches the controller with a null user rather than being rejected up
 * front. That is why the null case is answered with 401 here instead of being
 * assumed away: several existing Api controllers treat "no user" as a global
 * admin, and a delete endpoint must never inherit that.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'You must be signed in to perform this action.',
            ], 401);
        }

        $allowed = array_filter(array_map([Role::class, 'idForSlug'], $roles), 'is_int');

        if (!in_array((int) $user->role_id, $allowed, true)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        return $next($request);
    }
}
