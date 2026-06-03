<?php

namespace App\Http\Controllers;

use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * GET /api/audit-logs — Admin only, company-scoped, paginated, filterable.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $perPage   = max(1, min(100, $request->integer('per_page', 25)));

        $query = AuditLog::query()
            ->forCompany($companyId)
            ->with('user:id,name');

        if ($action = $request->string('action')->toString()) {
            $query->forAction($action);
        }
        if ($type = $request->string('model_type')->toString()) {
            $query->where('model_type', 'like', "%{$type}%");
        }
        if ($userId = $request->integer('user_id')) {
            $query->where('user_id', $userId);
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        $logs = $query->orderByDesc('created_at')->paginate($perPage);

        return ApiResponse::paginated($logs, 'Audit logs retrieved successfully', AuditLogResource::class);
    }

    /**
     * GET /api/audit-logs/{auditLog} — Admin only.
     */
    public function show(Request $request, AuditLog $auditLog): JsonResponse
    {
        abort_if($auditLog->company_id !== $request->user()->company_id, 404);

        $auditLog->load('user:id,name');

        return ApiResponse::success(new AuditLogResource($auditLog), 'Audit log retrieved successfully');
    }
}
