<?php

namespace App\Http\Requests\VehicleType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleTypeRequest extends FormRequest
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
        return [
            'type_name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('vehicle_types', 'type_name')
                    ->whereNull('deleted_at') // chỉ unique trong các bản ghi chưa xóa
            ],
            'description' => 'nullable|string',
            'hourly_rate' => 'required|numeric|min:0',
            'daily_rate' => 'required|numeric|min:0',
            'monthly_rate' => 'required|numeric|min:0',
        ];
    }
}
