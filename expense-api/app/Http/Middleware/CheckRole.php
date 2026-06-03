<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::error('Authentication required', [], 401);
        }

        if (! in_array($user->role->value, $roles, true)) {
            return ApiResponse::error('Insufficient permissions', [], 403);
        }

        return $next($request);
    }
}
