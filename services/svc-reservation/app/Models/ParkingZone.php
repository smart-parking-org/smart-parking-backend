<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParkingZone extends Model
{
    protected $fillable = [
        'code',
        'name',
        'capacity',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'capacity' => 'integer',
    ];

    public function slots()
    {
        return $this->hasMany(ParkingSlot::class, 'zone_id');
    }
}
