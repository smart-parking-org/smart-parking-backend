<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Gate extends Model
{
    use HasFactory;

    protected $fillable = [
        'parking_lot_id',
        'gate_code',
        'gate_type',
        'position_x',
        'position_y',
        'is_active',
    ];

    protected $casts = [
        'position_x' => 'decimal:2',
        'position_y' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function parkingLot()
    {
        return $this->belongsTo(ParkingLot::class);
    }

    public function slotDistances()
    {
        return $this->hasMany(SlotGateDistance::class);
    }

    public function slots()
    {
        return $this->belongsToMany(ParkingSlot::class, 'slot_gate_distances')
            ->withPivot('distance')
            ->withTimestamps();
    }
}
