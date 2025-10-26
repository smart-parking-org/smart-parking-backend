<?php

namespace App\Http\Requests\Reservation;

use Illuminate\Foundation\Http\FormRequest;

class ReservationStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Có thể thêm logic authorization sau
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'parking_lot_id' => 'required|integer|exists:parking_lots,id',
            'user_id' => 'required|integer',
            'vehicle_id' => 'required|integer',
            'desired_start_time' => 'required|date|after_or_equal:now',
            'duration_minutes' => 'required|integer|min:30|max:1440',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'parking_lot_id.required' => 'Vui lòng chọn bãi đỗ xe',
            'parking_lot_id.exists' => 'Bãi đỗ xe không tồn tại',
            'vehicle_type.required' => 'Vui lòng chọn loại xe',
            'vehicle_type.in' => 'Loại xe không hợp lệ',
            'desired_start_time.required' => 'Vui lòng chọn thời gian bắt đầu',
            'desired_start_time.after_or_equal' => 'Thời gian bắt đầu phải từ hiện tại trở đi',
            'duration_minutes.required' => 'Vui lòng chọn thời lượng đỗ',
            'duration_minutes.min' => 'Thời lượng đỗ tối thiểu 30 phút',
            'duration_minutes.max' => 'Thời lượng đỗ tối đa 24 giờ',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'parking_lot_id' => 'bãi đỗ xe',
            'vehicle_type' => 'loại xe',
            'desired_start_time' => 'thời gian bắt đầu',
            'duration_minutes' => 'thời lượng đỗ',
        ];
    }
}
