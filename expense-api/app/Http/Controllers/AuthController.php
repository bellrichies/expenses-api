<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    #[OA\Post(
        path: '/auth/register',
        summary: 'Register a new company and its first Admin user',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password', 'password_confirmation', 'company_name', 'company_email'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Jane Smith'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'jane@acme.com'),
                    new OA\Property(property: 'password', type: 'string', minLength: 8, example: 'secret123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', example: 'secret123'),
                    new OA\Property(property: 'company_name', type: 'string', example: 'Acme Corp'),
                    new OA\Property(property: 'company_email', type: 'string', format: 'email', example: 'info@acme.com'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Registration successful — token returned'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'email'        => 'required|string|email|max:255|unique:users',
            'password'     => ['required', 'string', 'confirmed', Password::min(8)],
            'company_name' => 'required|string|max:255|unique:companies,name',
            'company_email'=> 'required|email|max:255|unique:companies,email',
        ]);

        $company = Company::create([
            'name'  => $validated['company_name'],
            'email' => $validated['company_email'],
        ]);

        $user = User::create([
            'company_id' => $company->id,
            'name'       => $validated['name'],
            'email'      => $validated['email'],
            'password'   => Hash::make($validated['password']),
            'role'       => UserRole::Admin,
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return ApiResponse::success([
            'user'       => $this->userPayload($user, $company),
            'token'      => $token,
            'token_type' => 'Bearer',
        ], 'User registered successfully', 201);
    }

    #[OA\Post(
        path: '/auth/login',
        summary: 'Login and receive a Bearer token',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Login successful — token returned'),
            new OA\Response(response: 422, description: 'Wrong credentials'),
        ]
    )]
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::with('company')->where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return ApiResponse::success([
            'user'       => $this->userPayload($user, $user->company),
            'token'      => $token,
            'token_type' => 'Bearer',
        ], 'Login successful');
    }

    #[OA\Post(
        path: '/auth/logout',
        summary: 'Revoke the current access token',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Logged out'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Logged out successfully');
    }

    #[OA\Get(
        path: '/auth/user',
        summary: "Get the authenticated user's profile",
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Profile retrieved'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('company');

        return ApiResponse::success(
            ['user' => $this->userPayload($user, $user->company)],
            'User profile retrieved'
        );
    }

    private function userPayload(User $user, Company $company): array
    {
        return [
            'id'      => $user->id,
            'name'    => $user->name,
            'email'   => $user->email,
            'role'    => $user->role->value,
            'company' => [
                'id'    => $company->id,
                'name'  => $company->name,
                'email' => $company->email,
            ],
        ];
    }
}
