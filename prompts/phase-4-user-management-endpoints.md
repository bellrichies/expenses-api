# Phase 4: User Management Endpoints - Professional Copilot Prompt

## 🎯 Objective
Implement Admin-only User management — `GET /api/users`, `POST /api/users`, `PUT /api/users/{id}` (and optional `DELETE`) — with per-company unique email enforcement, role-enum validation, password hashing, async welcome email, and full company isolation.

> **Depends on:** Phase 1 (User model), Phase 2 (`role:Admin` middleware, Sanctum).

## 📋 Implementation Requirements

### 4.1 Form Requests

#### StoreUserRequest
Create `app/Http/Requests/StoreUserRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'Admin';
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => [
                'required', 'email', 'max:255',
                // Email must be unique *within the same company*.
                Rule::unique('users', 'email')->where(
                    fn ($q) => $q->where('company_id', $companyId)
                ),
            ],
            'password' => ['nullable', 'string', Password::min(8)],
            'role'     => ['required', Rule::in(['Admin', 'Manager', 'Employee'])],
        ];
    }
}
```

#### UpdateUserRequest
Create `app/Http/Requests/UpdateUserRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'Admin';
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;
        $userId    = $this->route('user')->id ?? null;

        return [
            'name'  => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'required', 'email', 'max:255',
                Rule::unique('users', 'email')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($userId),
            ],
            'role'  => ['sometimes', 'required', Rule::in(['Admin', 'Manager', 'Employee'])],
        ];
    }
}
```

### 4.2 UserResource
Create `app/Http/Resources/UserResource.php`:
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'email'      => $this->email,
            'role'       => $this->role,
            'company'    => $this->whenLoaded('company', fn () => [
                'id'   => $this->company->id,
                'name' => $this->company->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

### 4.3 Welcome Email Job (async)
Create `app/Jobs/SendWelcomeEmail.php`:
```php
<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use App\Mail\WelcomeUserMail;

class SendWelcomeEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public User $user, public string $temporaryPassword) {}

    public function handle(): void
    {
        Mail::to($this->user->email)->send(
            new WelcomeUserMail($this->user, $this->temporaryPassword)
        );
    }
}
```

> Create a matching Mailable (`php artisan make:mail WelcomeUserMail --markdown=emails.welcome`) and a Blade template. In local/dev, set `MAIL_MAILER=log` so the email is written to `storage/logs/laravel.log`.

### 4.4 UserController
Create `app/Http/Controllers/UserController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Jobs\SendWelcomeEmail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /**
     * GET /api/users — Admin only, company-scoped, paginated, filterable by role.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $perPage   = (int) $request->integer('per_page', 15);

        $query = User::query()
            ->forCompany($companyId)
            ->with('company:id,name');

        if ($role = $request->string('role')->toString()) {
            $query->where('role', $role);
        }

        $users = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Users retrieved successfully',
            'data'    => UserResource::collection($users)->resolve(),
            'meta'    => [
                'current_page' => $users->currentPage(),
                'per_page'     => $users->perPage(),
                'total'        => $users->total(),
                'last_page'    => $users->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/users — Admin only. Creates a user in the admin's company.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        // Generate a temporary password if none supplied.
        $plainPassword = $request->validated('password') ?? Str::password(12);

        $user = User::create([
            'company_id' => $companyId,
            'name'       => $request->validated('name'),
            'email'      => $request->validated('email'),
            'password'   => Hash::make($plainPassword),
            'role'       => $request->validated('role'),
        ]);

        // Async welcome email with the temporary password.
        SendWelcomeEmail::dispatch($user, $plainPassword);

        // Invalidate the cached company-users list (Phase 6).
        Cache::forget("company.{$companyId}.users");

        $user->load('company:id,name');

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data'    => new UserResource($user),
        ], 201);
    }

    /**
     * PUT /api/users/{user} — Admin only. Updates profile/role.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user); // UserPolicy (Phase 5)

        $user->update($request->validated());

        Cache::forget("company.{$user->company_id}.users");
        $user->load('company:id,name');

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data'    => new UserResource($user),
        ]);
    }

    /**
     * DELETE /api/users/{user} — Admin only (bonus / blueprint Phase 2.2).
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        // Guard: an admin cannot delete themselves.
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account',
                'errors'  => [],
            ], 422);
        }

        $user->delete();
        Cache::forget("company.{$user->company_id}.users");

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
            'data'    => null,
        ]);
    }
}
```

### 4.5 Scoped Route Binding & Routes
Add to `RouteServiceProvider::boot()` (same pattern as Phase 3):
```php
Route::bind('user', function (string $value) {
    $query = \App\Models\User::where('id', $value);
    if ($authUser = request()->user()) {
        $query->where('company_id', $authUser->company_id);
    }
    return $query->firstOrFail();
});
```

Update `routes/api.php`:
```php
use App\Http\Controllers\UserController;

Route::middleware(['auth:sanctum', 'company.scope', 'role:Admin'])->group(function () {
    Route::get('users', [UserController::class, 'index']);
    Route::post('users', [UserController::class, 'store']);
    Route::put('users/{user}', [UserController::class, 'update']);
    Route::delete('users/{user}', [UserController::class, 'destroy']);
});
```

## 🔍 Quality Gates

### Before Moving to Phase 5
1. ✅ **Non-admin (Manager/Employee) gets 403** on every `/api/users` route.
2. ✅ **Duplicate email in same company → 422**; same email in a *different* company succeeds.
3. ✅ **Created user belongs to admin's company** (never client-controlled).
4. ✅ **Welcome email job is queued** — verify with `php artisan queue:work` or in `failed_jobs`/logs.
5. ✅ **Updating role re-validates the enum** — `role:Owner` → 422.

## 🚀 Validation Commands
```bash
# Create a user (as Admin)
curl -X POST http://localhost:8000/api/users \
  -H "Authorization: Bearer ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"Jane","email":"jane@acme.test","role":"Manager"}'

# List filtered by role
curl "http://localhost:8000/api/users?role=Manager" -H "Authorization: Bearer ADMIN_TOKEN"

php artisan queue:work --once   # process the welcome email job
```

## 📝 Expected File Structure
```
app/Http/Controllers/UserController.php
app/Http/Requests/StoreUserRequest.php
app/Http/Requests/UpdateUserRequest.php
app/Http/Resources/UserResource.php
app/Jobs/SendWelcomeEmail.php
app/Mail/WelcomeUserMail.php
resources/views/emails/welcome.blade.php
routes/api.php                          (updated)
```

## ⚠️ Critical Implementation Notes
1. **Unique email is per-company**, not global — use the scoped `Rule::unique`.
2. **`company_id` always derives from the acting admin**, never from the request body.
3. **Hash every password** with `Hash::make()`; never log the plaintext temporary password (only email it).
4. **Self-deletion guard** prevents an admin locking the company out.
5. **Bust the users cache** on every create/update/delete (ties into Phase 6 Redis caching).

## 🎯 Success Criteria
✅ All user endpoints are Admin-only and company-scoped
✅ Per-company unique email enforced
✅ Role enum strictly validated on create + update
✅ Welcome email dispatched asynchronously
✅ Cache invalidated on mutations
✅ Ready for Phase 5: Authorization & Policies
