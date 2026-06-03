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

class AuthController extends Controller
{
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

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Logged out successfully');
    }

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
