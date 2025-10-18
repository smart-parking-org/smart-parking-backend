<?php

namespace App\Http\Requests\User;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $id = $this->id;
        return [
            'name' => 'bail|sometimes|required|string|max:100',
            'email' => 'bail|sometimes|required|email:rfc,dns|max:150|unique:users,email,' . $id,
            'phone' => [
                'bail',
                'sometimes',
                'required',
                'string',
                'max:15',
                'regex:/^(0|\+84)(3[2-9]|5[2689]|7[0|6-9]|8[1-9]|9[0-9])[0-9]{7}$/'
            ],
            'role' => ['bail', 'sometimes', 'required', Rule::in(array_column(UserRole::cases(), 'value'))],
            'is_active' => ['bail', 'sometimes', 'boolean']
        ];
    }

    public function messages()
    {
        return [
            // Họ và tên
            'name.required' => 'Vui lòng nhập họ và tên',
            'name.string' => 'Họ và tên phải là chuỗi ký tự',
            'name.max' => 'Họ và tên không được vượt quá :max ký tự',

            // Email
            'email.required' => 'Vui lòng nhập email',
            'email.email' => 'Email không hợp lệ',
            'email.max' => 'Email không được vượt quá :max ký tự',
            'email.unique' => 'Email đã được sử dụng',

            // Số điện thoại
            'phone.required' => 'Vui lòng nhập số điện thoại',
            'phone.string' => 'Số điện thoại phải là chuỗi ký tự',
            'phone.max' => 'Số điện thoại không hợp lệ',
            'phone.regex' => 'Số điện thoại không hợp lệ',

            // Vai trò
            'role.required' => 'Vui lòng chọn vai trò',
            'role.in' => 'Vai trò không hợp lệ',
        ];
    }
}
