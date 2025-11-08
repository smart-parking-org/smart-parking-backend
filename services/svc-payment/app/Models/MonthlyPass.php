<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MonthlyPass extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'vehicle_id',
        'parking_lot_id',
        'months',
        'start_date',
        'end_date',
        'amount',
        'status',
        'order_id',
        'txn_ref',
        'user_snapshot',
        'vehicle_snapshot',
    ];

    protected $casts = [
        'months' => 'integer',
        'amount' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'user_snapshot' => 'array',
        'vehicle_snapshot' => 'array',
    ];

    /**
     * Quan hệ với ParkingLot
     */
    public function parkingLot()
    {
        return $this->belongsTo(ParkingLot::class);
    }
}

