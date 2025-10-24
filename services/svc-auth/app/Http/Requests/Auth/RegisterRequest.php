<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'bail|required|string|max:100',
            'email' => 'bail|required|email:rfc,dns|max:150|unique:users,email',
            'password' => 'bail|required|string|min:8',
            'phone' => [
                'bail',
                'required',
                'string',
                'max:15', // để phòng trường hợp nhập +84
                'unique:users,phone',
                'regex:/^(0|\+84)(3[2-9]|5[2689]|7[0|6-9]|8[1-9]|9[0-9])[0-9]{7}$/'
            ],
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

            // Mật khẩu
            'password.required' => 'Vui lòng nhập mật khẩu',
            'password.string' => 'Mật khẩu phải là chuỗi ký tự',
            'password.min' => 'Mật khẩu phải có ít nhất :min ký tự',

            // Số điện thoại
            'phone.required' => 'Vui lòng nhập số điện thoại',
            'phone.string' => 'Số điện thoại phải là chuỗi ký tự',
            'phone.max' => 'Số điện thoại không hợp lệ',
            'phone.unique' => 'Số điện thoại đã được sử dụng',
        ];
    }
}
