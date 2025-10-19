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
        'priority_score',
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
        'priority_score' => 'float',
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

    // Tính điểm ưu tiên dựa trên thời gian đặt và loại xe
    public function calculatePriorityScore(): float
    {
        $baseScore = 1000; // Điểm cơ bản

        // Giảm điểm theo thời gian (đặt sớm hơn = điểm cao hơn)
        $timePenalty = now()->diffInMinutes($this->requested_at) * 0.1;

        // Ưu tiên theo loại xe (xe máy ưu tiên cao nhất)
        $vehiclePriority = match ($this->vehicle_type) {
            'motorbike' => 0,
            'car_4_seat' => 10,
            'car_7_seat' => 20,
            'light_truck' => 30,
            default => 50
        };

        // Bonus điểm nếu đặt trong giờ cao điểm
        $peakBonus = $this->isPeakHour() ? 50 : 0;

        return $baseScore - $timePenalty - $vehiclePriority + $peakBonus;
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
