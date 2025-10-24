<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParkingLot extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'gate_pos_x',
        'gate_pos_y'
    ];
    public function slots()
    {
        return $this->hasMany(ParkingSlot::class, 'parking_lot_id');
    }

    public function pricingRules()
    {
        return $this->hasMany(PricingRule::class);
    }

    public function peakHours()
    {
        return $this->hasMany(PeakHour::class);
    }

    public function reservationRequests()
    {
        return $this->hasMany(ReservationRequest::class);
    }
}
