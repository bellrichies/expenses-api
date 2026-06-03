<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    /**
     * GET /api/expenses — paginated, searchable, company-scoped list.
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

        $sortBy    = in_array($request->input('sort_by'), ['amount', 'title', 'created_at'], true)
            ? $request->input('sort_by')
            : 'created_at';
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';

        $expenses = $query->orderBy($sortBy, $direction)->paginate($perPage);

        return ApiResponse::paginated($expenses, 'Expenses retrieved successfully', ExpenseResource::class);
    }

    /**
     * GET /api/expenses/{expense}
     */
    public function show(Expense $expense): JsonResponse
    {
        $this->authorize('view', $expense);

        $expense->load(['user:id,name,company_id', 'company:id,name']);

        return ApiResponse::success(new ExpenseResource($expense), 'Expense retrieved successfully');
    }

    /**
     * POST /api/expenses — auto-assigns user_id + company_id.
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

        // Cache busting is handled by ExpenseObserver::created().
        $expense->load(['user:id,name,company_id', 'company:id,name']);

        return ApiResponse::success(new ExpenseResource($expense), 'Expense created successfully', 201);
    }

    /**
     * PUT /api/expenses/{expense} — Manager and Admin only.
     */
    public function update(UpdateExpenseRequest $request, Expense $expense): JsonResponse
    {
        $this->authorize('update', $expense);

        $expense->update($request->validated());

        // Cache busting is handled by ExpenseObserver::updated().
        $expense->load(['user:id,name,company_id', 'company:id,name']);

        return ApiResponse::success(new ExpenseResource($expense), 'Expense updated successfully');
    }

    /**
     * DELETE /api/expenses/{expense} — Admin only.
     */
    public function destroy(Expense $expense): JsonResponse
    {
        $this->authorize('delete', $expense);

        // Cache busting is handled by ExpenseObserver::deleted().
        $expense->delete();

        return ApiResponse::success(null, 'Expense deleted successfully');
    }
}
