<?php

namespace App\Http\Requests\User;

use App\Enums\AccountStatus;
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
                'max:15', // để phòng trường hợp nhập +84
                'unique:users,phone,' . $id,
                'regex:/^(0|\+84)(3[2-9]|5[2689]|7[0|6-9]|8[1-9]|9[0-9])[0-9]{7}$/'
            ],
            'apartment_code' => 'bail|nullable|string|max:50',
            'role' => ['bail', 'sometimes', 'required', Rule::in(array_column(UserRole::cases(), 'value'))],
            'status' => ['bail', 'sometimes', 'required', Rule::in(array_column(AccountStatus::cases(), 'value'))],
            'rejected_reason' => 'bail|nullable|string|max:255'
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
            'phone.unique' => 'Số điện thoại đã được sử dụng',
            'phone.regex' => 'Số điện thoại không hợp lệ',

            // Mã căn hộ
            'apartment_code.required' => 'Vui lòng nhập mã căn hộ',
            'apartment_code.string' => 'Mã căn hộ phải là chuỗi ký tự',
            'apartment_code.max' => 'Mã căn hộ không được vượt quá :max ký tự',

            // Vai trò
            'role.required' => 'Vui lòng chọn vai trò',
            'role.in' => 'Vai trò không hợp lệ',

            // Trạng thái
            'status.required' => 'Vui lòng chọn trạng thái',
            'status.in' => 'Trạng thái không hợp lệ',

            // Trạng thái
            'rejected_reason.string' => 'Lý do từ chối phải là chuỗi ký tự',
            'rejected_reason.max' => 'Lý do từ chối không thể vượt quá :max ký tự',
        ];
    }
}
