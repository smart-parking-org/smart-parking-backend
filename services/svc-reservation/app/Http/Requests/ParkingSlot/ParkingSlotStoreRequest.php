<?php

namespace App\Http\Requests\ParkingSlot;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ParkingSlotStoreRequest extends FormRequest
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
        $zoneId = (int) $this->input('zone_id');
        return [
            'zone_id' => 'required|exists:parking_zones,id',
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('parking_slots', 'code')->where(fn($q) => $q->where('zone_id', $zoneId)),
            ],
            'vehicle_type' => 'required|string|max:30',
            'status' => 'nullable|in:available,hold,reserved,occupied,maintenance,offline',
            'is_active' => 'boolean',
        ];
    }
}
