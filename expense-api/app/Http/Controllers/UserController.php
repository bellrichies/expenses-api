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
use OpenApi\Attributes as OA;

class UserController extends Controller
{
    #[OA\Get(
        path: '/users',
        summary: 'List company users (Admin only, paginated, role-filterable)',
        security: [['sanctum' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'role', in: 'query', schema: new OA\Schema(type: 'string', enum: ['Admin', 'Manager', 'Employee'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated user list (served from Redis cache)'),
            new OA\Response(response: 403, description: 'Admin role required'),
        ]
    )]
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

    #[OA\Post(
        path: '/users',
        summary: "Create a user in the Admin's company (async welcome email dispatched)",
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'role'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Bob'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'bob@acme.com'),
                    new OA\Property(property: 'role', type: 'string', enum: ['Admin', 'Manager', 'Employee']),
                    new OA\Property(property: 'password', type: 'string', description: 'Optional — auto-generated if omitted'),
                ]
            )
        ),
        tags: ['Users'],
        responses: [
            new OA\Response(response: 201, description: 'User created'),
            new OA\Response(response: 403, description: 'Admin role required'),
            new OA\Response(response: 422, description: 'Duplicate email within company or invalid role'),
        ]
    )]
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

    #[OA\Put(
        path: '/users/{id}',
        summary: "Update a user's name, email, or role (Admin only)",
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'role', type: 'string', enum: ['Admin', 'Manager', 'Employee']),
                ]
            )
        ),
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'User updated'),
            new OA\Response(response: 403, description: 'Admin role required'),
            new OA\Response(response: 404, description: 'User not found or cross-company'),
        ]
    )]
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $user->update($request->validated());

        Cache::forget(CacheKeys::companyUsers($user->company_id));
        $user->load('company:id,name');

        return ApiResponse::success(new UserResource($user), 'User updated successfully');
    }

    #[OA\Delete(
        path: '/users/{id}',
        summary: 'Delete a user (Admin only — self-delete returns 422)',
        security: [['sanctum' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'User deleted'),
            new OA\Response(response: 403, description: 'Admin role required'),
            new OA\Response(response: 404, description: 'User not found or cross-company'),
            new OA\Response(response: 422, description: 'Cannot delete own account'),
        ]
    )]
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return ApiResponse::error('You cannot delete your own account', [], 422);
        }

        $this->authorize('delete', $user);

        $companyId = $user->company_id;
        $user->delete();

        Cache::forget(CacheKeys::companyUsers($companyId));

        return ApiResponse::success(null, 'User deleted successfully');
    }
}
