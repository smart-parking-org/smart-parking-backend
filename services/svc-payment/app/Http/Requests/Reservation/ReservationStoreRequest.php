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
            'user_id' => 'nullable|integer|min:1',
            'vehicle_id' => 'nullable|integer|min:1',
            'vehicle_type' => 'required|string|in:motorbike,car_4_seat,car_7_seat,light_truck',
            'algorithm' => 'nullable|string|in:priority_queue,hungarian',
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
            'algorithm.in' => 'Thuật toán không hợp lệ',
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
            'algorithm' => 'thuật toán',
        ];
    }
}
