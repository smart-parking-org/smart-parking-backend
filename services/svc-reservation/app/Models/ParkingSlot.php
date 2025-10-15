<?php

namespace App\Models;

use App\Enums\SlotStatus;
use Illuminate\Database\Eloquent\Model;

class ParkingSlot extends Model
{
    protected $fillable = [
        'zone_id',
        'code',
        'vehicle_type',
        'status',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'status' => SlotStatus::class,
        ];
    }

    public function zone()
    {
        return $this->belongsTo(ParkingZone::class, 'zone_id');
    }
}
