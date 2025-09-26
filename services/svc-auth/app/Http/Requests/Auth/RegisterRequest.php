<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $cccd = $this->input('cccd');

        $this->merge([
            'cccd_hash' => $cccd ? sha1($cccd) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'email' => 'required|string|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:20|unique:users,phone',
            'password' => 'required|string|min:8',
            'apartment_code' => 'nullable|string|max:50',
            'cccd' => 'string|size:12',
            'cccd_hash' => 'nullable|unique:users,cccd_hash'
        ];
    }
}
