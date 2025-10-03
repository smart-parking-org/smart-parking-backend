<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Chuẩn hoá phone về digits, chuyển +84xxxx -> 0xxxx (đơn giản cho VN)
        if ($this->has('phone')) {
            $raw = (string) $this->input('phone');
            $digits = preg_replace('/\D+/', '', $raw);
            if (str_starts_with($raw, '+84') && str_starts_with($digits, '84')) {
                $digits = '0' . substr($digits, 2);
            }
            $this->merge(['phone' => $digits]);
        }

        // Tạo hash/masked cho CCCD
        if ($this->has('cccd')) {
            $cccd = (string) $this->input('cccd');
            $this->merge([
                'cccd_hash' => hash_hmac('sha256', $cccd, config('app.key')),
                'cccd_masked' => substr($cccd, 0, 3) . '******' . substr($cccd, -3),
            ]);
        }
    }

    public function rules(): array
    {
        // Cho phép re-apply/restore: ignore chính record nếu đã tồn tại theo email
        $existing = User::withTrashed()->where('email', $this->input('email'))->first();
        $userId = $existing?->id;

        return [
            'name' => 'bail|required|string|min:2|max:100',
            'email' => ['bail', 'required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['bail', 'required', 'digits_between:9,11', Rule::unique('users', 'phone')->ignore($userId)],
            'password' => 'required|string|min:8',
            'apartment_code' => 'required|string|max:50',

            'cccd' => ['bail', 'required', 'digits:12'],
            'cccd_hash' => [Rule::unique('users', 'cccd_hash')->ignore($userId)],
            'cccd_masked' => ['string', 'max:20'],
        ];
    }
}
