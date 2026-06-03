<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
                'errors' => [],
            ], 401);
        }

        // Compare enum backing value against the allowed role strings
        if (!in_array($user->role->value, $roles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions',
                'errors' => [],
            ], 403);
        }

        return $next($request);
    }
}
