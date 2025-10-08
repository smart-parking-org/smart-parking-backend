<?php

namespace App\Http\Requests\VehicleType;

use App\Models\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleTypeRequest extends FormRequest
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
        $id = $this->route('vehicle_type')?->id ?? $this->route('vehicle_type');

        return [
            'type_name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('vehicle_types', 'type_name')->ignore($id)->whereNull('deleted_at')
            ],
            'description' => 'nullable|string',
            'hourly_rate' => 'sometimes|required|numeric|min:0',
            'daily_rate' => 'sometimes|required|numeric|min:0',
            'monthly_rate' => 'sometimes|required|numeric|min:0',
        ];
    }
}
