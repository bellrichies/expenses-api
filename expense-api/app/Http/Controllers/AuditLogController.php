<?php

namespace App\Http\Controllers;

use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AuditLogController extends Controller
{
    #[OA\Get(
        path: '/audit-logs',
        summary: 'List audit logs (Admin only, company-scoped, filterable)',
        security: [['sanctum' => []]],
        tags: ['Audit Logs'],
        parameters: [
            new OA\Parameter(name: 'action', in: 'query', schema: new OA\Schema(type: 'string', enum: ['create', 'update', 'delete'])),
            new OA\Parameter(name: 'model_type', in: 'query', schema: new OA\Schema(type: 'string', example: 'Expense')),
            new OA\Parameter(name: 'user_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated audit log list'),
            new OA\Response(response: 403, description: 'Admin role required'),
        ]
    )]
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

    #[OA\Get(
        path: '/audit-logs/{id}',
        summary: 'Get a single audit log entry (Admin only, company-scoped)',
        security: [['sanctum' => []]],
        tags: ['Audit Logs'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Audit log detail'),
            new OA\Response(response: 403, description: 'Admin role required'),
            new OA\Response(response: 404, description: 'Not found or cross-company'),
        ]
    )]
    public function show(Request $request, AuditLog $auditLog): JsonResponse
    {
        abort_if($auditLog->company_id !== $request->user()->company_id, 404);

        $auditLog->load('user:id,name');

        return ApiResponse::success(new AuditLogResource($auditLog), 'Audit log retrieved successfully');
    }
}
