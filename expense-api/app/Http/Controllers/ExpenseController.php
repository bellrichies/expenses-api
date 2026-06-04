<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ExpenseController extends Controller
{
    #[OA\Get(
        path: '/expenses',
        summary: 'List expenses (paginated, searchable, company-scoped)',
        security: [['sanctum' => []]],
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Search title or category', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category', in: 'query', description: 'Exact category filter', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', description: 'created_at >=', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', description: 'created_at <=', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'sort_by', in: 'query', schema: new OA\Schema(type: 'string', enum: ['amount', 'title', 'created_at'])),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated expense list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $perPage   = max(1, min(100, $request->integer('per_page', 15)));

        $query = Expense::query()
            ->forCompany($companyId)
            ->with(['user:id,name,company_id', 'company:id,name']);

        if ($search = $request->string('search')->toString()) {
            $query->search($search);
        }
        if ($category = $request->string('category')->toString()) {
            $query->forCategory($category);
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        $sortBy    = in_array($request->input('sort_by'), ['amount', 'title', 'created_at'], true)
            ? $request->input('sort_by')
            : 'created_at';
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';

        $expenses = $query->orderBy($sortBy, $direction)->paginate($perPage);

        return ApiResponse::paginated($expenses, 'Expenses retrieved successfully', ExpenseResource::class);
    }

    #[OA\Get(
        path: '/expenses/{id}',
        summary: 'Get a single expense',
        security: [['sanctum' => []]],
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Expense detail'),
            new OA\Response(response: 403, description: 'Unauthorized (policy)'),
            new OA\Response(response: 404, description: 'Not found or cross-company'),
        ]
    )]
    public function show(Expense $expense): JsonResponse
    {
        $this->authorize('view', $expense);

        $expense->load(['user:id,name,company_id', 'company:id,name']);

        return ApiResponse::success(new ExpenseResource($expense), 'Expense retrieved successfully');
    }

    #[OA\Post(
        path: '/expenses',
        summary: 'Create an expense (company + user auto-derived from token)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'amount', 'category'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'Team lunch'),
                    new OA\Property(property: 'amount', type: 'number', format: 'float', example: 85.50),
                    new OA\Property(property: 'category', type: 'string', example: 'Food'),
                ]
            )
        ),
        tags: ['Expenses'],
        responses: [
            new OA\Response(response: 201, description: 'Expense created — audit log written'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $user = $request->user();

        $expense = Expense::create([
            'company_id' => $user->company_id,
            'user_id'    => $user->id,
            'title'      => $request->validated('title'),
            'amount'     => $request->validated('amount'),
            'category'   => $request->validated('category'),
        ]);

        $expense->load(['user:id,name,company_id', 'company:id,name']);

        return ApiResponse::success(new ExpenseResource($expense), 'Expense created successfully', 201);
    }

    #[OA\Put(
        path: '/expenses/{id}',
        summary: 'Update an expense (Manager|Admin only — audit-logged)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'amount', type: 'number', format: 'float'),
                    new OA\Property(property: 'category', type: 'string'),
                ]
            )
        ),
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Expense updated'),
            new OA\Response(response: 403, description: 'Insufficient permissions'),
            new OA\Response(response: 404, description: 'Not found or cross-company'),
        ]
    )]
    public function update(UpdateExpenseRequest $request, Expense $expense): JsonResponse
    {
        $this->authorize('update', $expense);

        $expense->update($request->validated());
        $expense->load(['user:id,name,company_id', 'company:id,name']);

        return ApiResponse::success(new ExpenseResource($expense), 'Expense updated successfully');
    }

    #[OA\Delete(
        path: '/expenses/{id}',
        summary: 'Delete an expense (Admin only — audit-logged)',
        security: [['sanctum' => []]],
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Expense deleted'),
            new OA\Response(response: 403, description: 'Insufficient permissions — Admin required'),
            new OA\Response(response: 404, description: 'Not found or cross-company'),
        ]
    )]
    public function destroy(Expense $expense): JsonResponse
    {
        $this->authorize('delete', $expense);

        $expense->delete();

        return ApiResponse::success(null, 'Expense deleted successfully');
    }
}
