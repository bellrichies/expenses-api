<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Jobs\SendWelcomeEmail;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\CacheKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
        $perPage   = max(1, min(100, $request->integer('per_page', 15)));
        $page      = max(1, $request->integer('page', 1));

        $allUsers = Cache::remember(
            CacheKeys::companyUsers($companyId),
            CacheKeys::TTL_USERS,
            fn () => User::forCompany($companyId)->with('company:id,name')->orderBy('name')->get()
        );

        if ($role = $request->string('role')->toString()) {
            $allUsers = $allUsers->filter(fn (User $u) => $u->role->value === $role)->values();
        }

        $total     = $allUsers->count();
        $items     = $allUsers->slice(($page - 1) * $perPage, $perPage)->values();
        $paginator = new LengthAwarePaginator($items, $total, $perPage, $page);

        return ApiResponse::paginated($paginator, 'Users retrieved successfully', UserResource::class);
    }

    /**
     * POST /api/users — Admin only, creates a user in the admin's company.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $plainPassword = $request->validated('password') ?? Str::password(12);

        $user = User::create([
            'company_id' => $companyId,
            'name'       => $request->validated('name'),
            'email'      => $request->validated('email'),
            'password'   => Hash::make($plainPassword),
            'role'       => $request->validated('role'),
        ]);

        SendWelcomeEmail::dispatch($user, $plainPassword);

        Cache::forget(CacheKeys::companyUsers($companyId));

        $user->load('company:id,name');

        return ApiResponse::success(new UserResource($user), 'User created successfully', 201);
    }

    /**
     * PUT /api/users/{user} — Admin only, updates name/email/role.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $user->update($request->validated());

        Cache::forget(CacheKeys::companyUsers($user->company_id));
        $user->load('company:id,name');

        return ApiResponse::success(new UserResource($user), 'User updated successfully');
    }

    /**
     * DELETE /api/users/{user} — Admin only.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        if ($user->id === $request->user()->id) {
            return ApiResponse::error('You cannot delete your own account', [], 422);
        }

        $companyId = $user->company_id;
        $user->delete();

        Cache::forget(CacheKeys::companyUsers($companyId));

        return ApiResponse::success(null, 'User deleted successfully');
    }
}
