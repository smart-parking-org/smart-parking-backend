<?php

namespace App\Http\Requests\VehicleType;

use App\Models\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VehicleTypeUpdateRequest extends FormRequest
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
        $id = $this->route('vehicle_type');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                'regex:/^[A-Z0-9_]+$/',
                Rule::unique('vehicle_types', 'code')->ignore($id),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
