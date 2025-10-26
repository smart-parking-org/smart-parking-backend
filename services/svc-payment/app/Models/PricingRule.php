<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PricingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'parking_lot_id',
        'vehicle_type',
        'hourly',
        'daily_cap',
        'monthly_pass',
        'peak_enabled',
        'peak_multiplier',
    ];

    protected $casts = [
        'hourly' => 'float',
        'daily_cap' => 'float',
        'monthly_pass' => 'float',
        'peak_enabled' => 'boolean',
        'peak_multiplier' => 'float',
    ];

    /**
     * Bãi áp dụng bảng giá này
     */
    public function parkingLot()
    {
        return $this->belongsTo(ParkingLot::class);
    }
}
