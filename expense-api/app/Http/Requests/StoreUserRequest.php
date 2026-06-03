<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => [
                'required', 'email', 'max:255',
                // Unique within the same company — the same address may exist in other tenants.
                Rule::unique('users', 'email')->where(
                    fn ($q) => $q->where('company_id', $companyId)
                ),
            ],
            'password' => ['nullable', 'string', Password::min(8)],
            'role'     => ['required', Rule::in(['Admin', 'Manager', 'Employee'])],
        ];
    }
}
