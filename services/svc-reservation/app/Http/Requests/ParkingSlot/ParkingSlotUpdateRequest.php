<?php

namespace App\Http\Requests\ParkingSlot;

use Illuminate\Foundation\Http\FormRequest;

class ParkingSlotUpdateRequest extends FormRequest
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
            'vehicle_type' => 'sometimes|string|max:30',
            'status' => 'sometimes|in:available,hold,reserved,occupied,maintenance,offline',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
