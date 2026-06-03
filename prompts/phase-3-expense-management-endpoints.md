# Phase 3: Expense Management Endpoints - Professional Copilot Prompt

## 🎯 Objective
Implement the full Expense CRUD API with multi-tenant scoping, FormRequest validation, eager loading, pagination, search/filtering, and role-aware authorization — covering `GET/POST/PUT/DELETE /api/expenses`.

> **Depends on:** Phase 1 (models/migrations) and Phase 2 (Sanctum + `auth:sanctum`, `role`, `company.scope` middleware).

## 📋 Implementation Requirements

### 3.1 Form Request Validation

#### StoreExpenseRequest
Create `app/Http/Requests/StoreExpenseRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated user (Employee, Manager, Admin) may create expenses.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title'    => ['required', 'string', 'max:255'],
            'amount'   => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'category' => ['required', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'The amount must be zero or greater.',
        ];
    }
}
```

#### UpdateExpenseRequest
Create `app/Http/Requests/UpdateExpenseRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Fine-grained role check is delegated to ExpensePolicy (Phase 5).
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title'    => ['sometimes', 'required', 'string', 'max:255'],
            'amount'   => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99'],
            'category' => ['sometimes', 'required', 'string', 'max:100'],
        ];
    }
}
```

### 3.2 API Resource Transformers

#### ExpenseResource
Create `app/Http/Resources/ExpenseResource.php`:
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'title'      => $this->title,
            'amount'     => (float) $this->amount,
            'category'   => $this->category,
            'user'       => $this->whenLoaded('user', fn () => [
                'id'   => $this->user->id,
                'name' => $this->user->name,
            ]),
            'company'    => $this->whenLoaded('company', fn () => [
                'id'   => $this->company->id,
                'name' => $this->company->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

### 3.3 Expense Controller

Create `app/Http/Controllers/ExpenseController.php`:
```php
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
        $perPage   = (int) $request->integer('per_page', 15);

        $query = Expense::query()
            ->forCompany($companyId)
            ->with(['user:id,name,company_id', 'company:id,name']);

        // Search by title OR category.
        if ($search = $request->string('search')->toString()) {
            $query->search($search);
        }

        // Exact category filter.
        if ($category = $request->string('category')->toString()) {
            $query->forCategory($category);
        }

        // Optional date-range filter.
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        // Whitelisted sorting.
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

        // Audit logging is handled automatically by ExpenseObserver (Phase 8).
        $expense->load(['user:id,name,company_id', 'company:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Expense created successfully',
            'data'    => new ExpenseResource($expense),
        ], 201);
    }

    /**
     * PUT /api/expenses/{expense}
     * Manager and Admin only (enforced by ExpensePolicy).
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
     * Admin only (enforced by ExpensePolicy).
     */
    public function destroy(Request $request, Expense $expense): JsonResponse
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
```

### 3.4 Route Model Binding & Company Isolation

To guarantee that route model binding never leaks cross-company rows, scope the binding in `app/Providers/RouteServiceProvider.php` **or** rely on the `ExpensePolicy` (Phase 5). The defensive belt-and-suspenders approach — add a global resolver in `boot()`:
```php
use App\Models\Expense;
use Illuminate\Support\Facades\Route;

public function boot(): void
{
    Route::bind('expense', function (string $value) {
        $query = Expense::where('id', $value);

        if ($user = request()->user()) {
            $query->where('company_id', $user->company_id);
        }

        return $query->firstOrFail(); // 404 instead of cross-company leak
    });

    // ... existing rate limiter config
}
```

### 3.5 Routes

Update `routes/api.php`:
```php
use App\Http\Controllers\ExpenseController;

Route::middleware(['auth:sanctum', 'company.scope'])->group(function () {
    Route::get('expenses', [ExpenseController::class, 'index']);
    Route::get('expenses/{expense}', [ExpenseController::class, 'show']);
    Route::post('expenses', [ExpenseController::class, 'store']);

    // PUT restricted to Manager + Admin
    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])
        ->middleware('role:Manager,Admin');

    // DELETE restricted to Admin
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])
        ->middleware('role:Admin');
});
```

> **Defense in depth:** Route middleware (`role:`) is the first gate; `ExpensePolicy` (Phase 5) is the second. Both must agree.

## 🔍 Quality Gates

### Before Moving to Phase 4

1. ✅ **List endpoint returns paginated, company-scoped data**:
   ```bash
   curl "http://localhost:8000/api/expenses?search=travel&per_page=10" \
     -H "Authorization: Bearer YOUR_TOKEN"
   ```
2. ✅ **Create auto-assigns user + company**:
   ```bash
   curl -X POST http://localhost:8000/api/expenses \
     -H "Authorization: Bearer YOUR_TOKEN" -H "Content-Type: application/json" \
     -d '{"title":"Taxi","amount":42.50,"category":"Travel"}'
   ```
3. ✅ **Employee receives 403 on PUT/DELETE** (role middleware).
4. ✅ **Manager receives 403 on DELETE** (Admin-only).
5. ✅ **Cross-company access returns 404** (scoped route binding) — request another company's expense ID.

## 🚀 Validation Commands
```bash
php artisan route:list --path=expenses
php artisan test --filter=ExpenseTest      # full coverage in Phase 10
```

## 📝 Expected File Structure
```
app/Http/Controllers/ExpenseController.php
app/Http/Requests/StoreExpenseRequest.php
app/Http/Requests/UpdateExpenseRequest.php
app/Http/Resources/ExpenseResource.php
routes/api.php                              (updated)
app/Providers/RouteServiceProvider.php      (updated — scoped binding)
```

## ⚠️ Critical Implementation Notes
1. **Never trust client-supplied `company_id`/`user_id`** — always derive from `$request->user()`.
2. **Scoped route binding** prevents IDOR (Insecure Direct Object Reference) across tenants.
3. **Eager load with column selection** (`user:id,name,company_id`) to minimize payload and kill N+1.
4. **Whitelist sort columns** to prevent SQL injection via `sort_by`.
5. **Audit logging is implicit** via the observer — do NOT manually write audit rows here.

## 🎯 Success Criteria
✅ All four expense endpoints functional and company-scoped
✅ Validation enforced through FormRequests
✅ Role restrictions enforced (Employee/Manager/Admin)
✅ Zero N+1 queries on the list endpoint
✅ Cross-company IDs return 404, never another tenant's data
✅ Ready for Phase 4: User Management Endpoints
