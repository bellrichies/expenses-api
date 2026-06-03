<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyScope
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
                'errors' => [],
            ], 401);
        }

        if (!$user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'User not associated with a company',
                'errors' => [],
            ], 403);
        }

        // Expose company_id on the request so controllers can reference it directly
        $request->merge(['company_id' => $user->company_id]);

        return $next($request);
    }
}
