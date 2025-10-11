<?php

namespace App\Http\Requests\Vehicle;

use Illuminate\Foundation\Http\FormRequest;

class VehicleStoreRequest extends FormRequest
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
            'user_id' => ['required', 'exists:users,id'],
            'type_id' => ['required', 'exists:vehicle_types,id'],
            'license_plate' => [
                'required',
                'unique:vehicles,license_plate',
                'regex:/^[0-9]{2}(?:[ABCEFGHKLMNPSTUVXYZ]{1,2})(?:[1-9])?-(?:[0-9]{4}|[0-9]{3}\.[0-9]{2})$/'
            ]
        ];
    }
}
