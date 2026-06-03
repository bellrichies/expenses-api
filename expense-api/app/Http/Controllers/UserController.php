<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Jobs\SendWelcomeEmail;
use App\Models\User;
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
     * The full company users list is cached; filtering and pagination are applied
     * in-memory on the cached collection so write invalidation is a single key.
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

        // Apply optional role filter on the cached collection.
        if ($role = $request->string('role')->toString()) {
            $allUsers = $allUsers->filter(fn (User $u) => $u->role->value === $role)->values();
        }

        $total  = $allUsers->count();
        $items  = $allUsers->slice(($page - 1) * $perPage, $perPage)->values();
        $paginator = new LengthAwarePaginator($items, $total, $perPage, $page);

        return response()->json([
            'success' => true,
            'message' => 'Users retrieved successfully',
            'data'    => UserResource::collection($paginator)->resolve(),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/users — Admin only. Creates a user in the admin's company.
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

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data'    => new UserResource($user),
        ], 201);
    }

    /**
     * PUT /api/users/{user} — Admin only. Updates name, email, or role.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $user->update($request->validated());

        Cache::forget(CacheKeys::companyUsers($user->company_id));
        $user->load('company:id,name');

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data'    => new UserResource($user),
        ]);
    }

    /**
     * DELETE /api/users/{user} — Admin only.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account',
                'errors'  => [],
            ], 422);
        }

        $companyId = $user->company_id;
        $user->delete();

        Cache::forget(CacheKeys::companyUsers($companyId));

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
            'data'    => null,
        ]);
    }
}
