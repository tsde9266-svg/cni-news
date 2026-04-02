<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckRole middleware
 *
 * Usage in routes: ->middleware('role:admin,editor,super_admin')
 *
 * Checks that the authenticated user has at least one of the specified roles.
 * Roles are stored in the roles table and mapped via user_role_map.
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // Not authenticated
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Load roles if not already loaded
        if (! $user->relationLoaded('roles')) {
            $user->load('roles');
        }

        $userRoles = $user->roles->pluck('slug')->toArray();

        // Check if user has any of the required roles
        foreach ($roles as $role) {
            if (in_array($role, $userRoles)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Forbidden. Insufficient permissions.',
            'required_roles' => $roles,
            'your_roles'     => $userRoles,
        ], 403);
    }
}
