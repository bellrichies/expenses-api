<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;

class CompanyScope
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::error('Authentication required', [], 401);
        }

        if (! $user->company_id) {
            return ApiResponse::error('User not associated with a company', [], 403);
        }

        $request->merge(['company_id' => $user->company_id]);

        return $next($request);
    }
}
