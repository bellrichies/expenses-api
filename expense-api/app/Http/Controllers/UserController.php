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
        $perPage   = max(1, min(100, $request->integer('per_page', 15)));

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

        // Use supplied password or auto-generate a secure temporary one.
        $plainPassword = $request->validated('password') ?? Str::password(12);

        $user = User::create([
            'company_id' => $companyId,
            'name'       => $request->validated('name'),
            'email'      => $request->validated('email'),
            'password'   => Hash::make($plainPassword),
            'role'       => $request->validated('role'),
        ]);

        // Dispatch async — the queue worker will actually send the mail.
        SendWelcomeEmail::dispatch($user, $plainPassword);

        Cache::forget("company.{$companyId}.users");

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

        Cache::forget("company.{$user->company_id}.users");
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

        // Prevent an admin from locking themselves out.
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account',
                'errors'  => [],
            ], 422);
        }

        $companyId = $user->company_id;
        $user->delete();

        Cache::forget("company.{$companyId}.users");

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
            'data'    => null,
        ]);
    }
}
