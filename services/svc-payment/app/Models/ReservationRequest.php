<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReservationRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'parking_lot_id',
        'user_id',
        'vehicle_id',
        'vehicle_type',
        'desired_start_time',
        'duration_minutes',
        'requested_at',
        'allocated_slot_id',
        'processed_at',
        'processing_time_ms',
        'status',
    ];

    protected $casts = [
        'parking_lot_id' => 'integer',
        'user_id' => 'integer',
        'vehicle_id' => 'integer',
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
        'processing_time_ms' => 'integer',
        'desired_start_time' => 'datetime',
        'duration_minutes' => 'integer',
    ];

    public function parkingLot()
    {
        return $this->belongsTo(ParkingLot::class);
    }

    public function allocatedSlot()
    {
        return $this->belongsTo(ParkingSlot::class, 'allocated_slot_id');
    }

    // Accessor để tự động tính end_time
    public function getDesiredEndTimeAttribute()
    {
        if (!$this->desired_start_time || !$this->duration_minutes) {
            return null;
        }

        return $this->desired_start_time->copy()->addMinutes($this->duration_minutes);
    }

    // Kiểm tra có phải giờ cao điểm không
    public function isPeakHour(): bool
    {
        if (!$this->desired_start_time) {
            return false;
        }

        $peakHours = PeakHour::where('parking_lot_id', $this->parking_lot_id)
            ->where('is_active', true)
            ->get();

        foreach ($peakHours as $peakHour) {
            if ($peakHour->isWithinPeak($this->desired_start_time)) {
                return true;
            }
        }

        return false;
    }
}
