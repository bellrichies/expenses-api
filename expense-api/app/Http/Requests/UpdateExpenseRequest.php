<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
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
