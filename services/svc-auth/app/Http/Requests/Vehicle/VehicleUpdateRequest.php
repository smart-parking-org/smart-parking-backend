<?php

namespace App\Http\Requests\Vehicle;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VehicleUpdateRequest extends FormRequest
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
        $id = $this->route('id');

        return [
            'vehicle_type' => ['sometimes', 'required', Rule::in(['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck'])],
            'license_plate' => [
                'sometimes',
                'required',
                Rule::unique('vehicles', 'license_plate')->ignore($id),
                'regex:/^[0-9]{2}(?:[ABCEFGHKLMNPSTUVXYZ]{1,2})(?:[1-9])?-(?:[0-9]{4}|[0-9]{3}\.[0-9]{2})$/'
            ]
        ];
    }
}
