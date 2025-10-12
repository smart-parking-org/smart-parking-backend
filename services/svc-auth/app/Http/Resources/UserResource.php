<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'apartment_code' => $this->apartment_code,
            'role' => $this->role,
            'status' => $this->status,
            'approver' => [
                'id' => $this->approver->id ?? null,
                'name' => $this->approver->name ?? null,
            ],
            'approved_at' => $this->approved_at,
            'rejected_reason' => $this->rejected_reason,
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
            'deleted_at' => optional($this->deleted_at)->toISOString(),
        ];
    }
}
