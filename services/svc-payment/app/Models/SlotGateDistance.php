<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SlotGateDistance extends Model
{
    use HasFactory;

    protected $fillable = [
        'slot_id',
        'gate_id',
        'distance',
    ];

    protected $casts = [
        'distance' => 'decimal:2',
    ];

    public function slot()
    {
        return $this->belongsTo(ParkingSlot::class);
    }

    public function gate()
    {
        return $this->belongsTo(Gate::class);
    }
}
