<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    /**
     * GET /api/expenses
     * Paginated, searchable, company-scoped list with eager loading.
     */
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

        $sortBy    = in_array($request->get('sort_by'), ['amount', 'title', 'created_at'], true)
            ? $request->get('sort_by')
            : 'created_at';
        $direction = $request->get('direction') === 'asc' ? 'asc' : 'desc';

        $expenses = $query->orderBy($sortBy, $direction)->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Expenses retrieved successfully',
            'data'    => ExpenseResource::collection($expenses)->resolve(),
            'meta'    => [
                'current_page' => $expenses->currentPage(),
                'per_page'     => $expenses->perPage(),
                'total'        => $expenses->total(),
                'last_page'    => $expenses->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/expenses/{expense}
     */
    public function show(Request $request, Expense $expense): JsonResponse
    {
        $this->authorize('view', $expense);

        $expense->load(['user:id,name,company_id', 'company:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Expense retrieved successfully',
            'data'    => new ExpenseResource($expense),
        ]);
    }

    /**
     * POST /api/expenses
     * Auto-assigns user_id + company_id from the authenticated user.
     */
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

        return response()->json([
            'success' => true,
            'message' => 'Expense created successfully',
            'data'    => new ExpenseResource($expense),
        ], 201);
    }

    /**
     * PUT /api/expenses/{expense}
     * Manager and Admin only (enforced by route middleware + ExpensePolicy).
     */
    public function update(UpdateExpenseRequest $request, Expense $expense): JsonResponse
    {
        $this->authorize('update', $expense);

        $expense->update($request->validated());
        $expense->load(['user:id,name,company_id', 'company:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Expense updated successfully',
            'data'    => new ExpenseResource($expense),
        ]);
    }

    /**
     * DELETE /api/expenses/{expense}
     * Admin only (enforced by route middleware + ExpensePolicy).
     */
    public function destroy(Expense $expense): JsonResponse
    {
        $this->authorize('delete', $expense);

        $expense->delete();

        return response()->json([
            'success' => true,
            'message' => 'Expense deleted successfully',
            'data'    => null,
        ]);
    }
}
