# Phase 9: API Response Standardization - Professional Copilot Prompt

## 🎯 Objective
Unify every endpoint behind a single, predictable JSON envelope (`success`, `message`, `data`, `errors`/`meta`) via a reusable `ApiResponse` helper, and centralize exception rendering so 401/403/404/422/500 all return the same shape.

> **Depends on:** All prior phases (controllers to refactor onto the helper).

## 📋 Implementation Requirements

### 9.1 The Response Envelope
Target contract for **all** responses:
```json
{
  "success": true,
  "message": "Expenses retrieved successfully",
  "data": { "...": "..." },
  "meta": { "current_page": 1, "per_page": 15, "total": 50, "last_page": 4 }
}
```
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": { "amount": ["The amount field is required."] }
}
```

### 9.2 ApiResponse Helper
Create `app/Support/ApiResponse.php`:
```php
<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'Success',
        int $status = 200,
        array $meta = []
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
            'data'    => $data instanceof JsonResource ? $data->resolve() : $data,
        ];

        if (! empty($meta)) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    public static function error(
        string $message = 'Error',
        array $errors = [],
        int $status = 400
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], $status);
    }

    /**
     * Standard envelope for a LengthAwarePaginator, optionally wrapping
     * items in an API Resource collection.
     */
    public static function paginated(
        LengthAwarePaginator $paginator,
        string $message = 'Success',
        ?string $resourceClass = null
    ): JsonResponse {
        $items = $resourceClass
            ? $resourceClass::collection($paginator->getCollection())->resolve()
            : $paginator->items();

        return self::success($items, $message, 200, [
            'current_page' => $paginator->currentPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'last_page'    => $paginator->lastPage(),
        ]);
    }
}
```

### 9.3 Refactor Controllers onto the Helper
Replace the inline `response()->json([...])` blocks from Phases 2–8. Example (`ExpenseController`):
```php
use App\Support\ApiResponse;
use App\Http\Resources\ExpenseResource;

public function index(Request $request): JsonResponse
{
    // ... build $expenses paginator ...
    return ApiResponse::paginated($expenses, 'Expenses retrieved successfully', ExpenseResource::class);
}

public function store(StoreExpenseRequest $request): JsonResponse
{
    // ... create $expense ...
    return ApiResponse::success(new ExpenseResource($expense), 'Expense created successfully', 201);
}

public function destroy(Request $request, Expense $expense): JsonResponse
{
    $this->authorize('delete', $expense);
    $expense->delete();
    return ApiResponse::success(null, 'Expense deleted successfully');
}
```

### 9.4 Centralized Exception Rendering
All framework exceptions must emit the error envelope.

**Laravel 11+** — `bootstrap/app.php`:
```php
->withExceptions(function (Illuminate\Foundation\Configuration\Exceptions $exceptions) {
    $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());

    $exceptions->render(function (Illuminate\Validation\ValidationException $e, $request) {
        return App\Support\ApiResponse::error('Validation failed', $e->errors(), 422);
    });
    $exceptions->render(function (Illuminate\Auth\AuthenticationException $e, $request) {
        return App\Support\ApiResponse::error('Unauthenticated', [], 401);
    });
    $exceptions->render(function (Illuminate\Auth\Access\AuthorizationException $e, $request) {
        return App\Support\ApiResponse::error('This action is unauthorized.', [], 403);
    });
    $exceptions->render(function (Illuminate\Database\Eloquent\ModelNotFoundException $e, $request) {
        return App\Support\ApiResponse::error('Resource not found', [], 404);
    });
    $exceptions->render(function (Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) {
        return App\Support\ApiResponse::error('Endpoint not found', [], 404);
    });
    $exceptions->render(function (Symfony\Component\HttpKernel\Exception\HttpException $e, $request) {
        return App\Support\ApiResponse::error($e->getMessage() ?: 'Error', [], $e->getStatusCode());
    });
    $exceptions->render(function (Throwable $e, $request) {
        if ($request->is('api/*') && ! config('app.debug')) {
            return App\Support\ApiResponse::error('Server error', [], 500);
        }
    });
});
```

**Laravel 10** — register the same `renderable` closures in `app/Exceptions/Handler.php@register()` (see Phase 2 for the pattern), each delegating to `ApiResponse::error(...)`.

### 9.5 Force JSON on the API Group
Guarantee API clients always get JSON (never an HTML error page). Add a lightweight middleware or set the `Accept` expectation:
```php
// app/Http/Middleware/ForceJsonResponse.php
public function handle($request, Closure $next)
{
    $request->headers->set('Accept', 'application/json');
    return $next($request);
}
```
Apply it to the `api` middleware group.

## 🔍 Quality Gates

### Before Moving to Phase 10
1. ✅ **Every 2xx response** matches `{success,message,data[,meta]}`.
2. ✅ **422** returns field-keyed `errors` from validation.
3. ✅ **401/403/404** return the error envelope, not Laravel HTML.
4. ✅ **Unknown route** under `/api/*` returns JSON 404.
5. ✅ **500 in production** hides internals (`Server error`), shows detail only when `APP_DEBUG=true`.

## 🚀 Validation Commands
```bash
# 422 shape
curl -X POST http://localhost:8000/api/expenses \
  -H "Authorization: Bearer TOKEN" -H "Content-Type: application/json" -d '{}'

# 401 shape
curl http://localhost:8000/api/expenses

# 404 shape (unknown endpoint)
curl http://localhost:8000/api/does-not-exist -H "Accept: application/json"
```

## 📝 Expected File Structure
```
app/Support/ApiResponse.php
app/Http/Middleware/ForceJsonResponse.php
bootstrap/app.php  /  app/Exceptions/Handler.php   (centralized rendering)
app/Http/Controllers/*.php                          (refactored to ApiResponse)
```

## ⚠️ Critical Implementation Notes
1. **Single source of truth** — every controller returns through `ApiResponse`; no ad-hoc `response()->json`.
2. **`meta` only for collections/pagination**; omit it for single-resource responses.
3. **`errors` only on failures**; keep success payloads clean.
4. **Never leak stack traces** in production — gate detailed 500s behind `APP_DEBUG`.
5. **Resolve resources** (`->resolve()`) inside the helper so the envelope isn't double-wrapped.

## 🎯 Success Criteria
✅ `ApiResponse` helper powers all responses
✅ Consistent success + error envelopes across every endpoint
✅ All framework exceptions mapped to correct status codes + envelope
✅ API always returns JSON, never HTML error pages
✅ Production 500s sanitized
✅ Ready for Phase 10: Testing & Validation
