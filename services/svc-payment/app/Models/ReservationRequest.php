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
        'status',
        'processed_at',
        'priority_score',
        'processed_at',
        'allocated_slot_id',
        'processing_time_ms'
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
        'priority_score' => 'float',
        'processing_time_ms' => 'integer',
    ];

    public function parkingLot()
    {
        return $this->belongsTo(ParkingLot::class);
    }

    public function allocatedSlot()
    {
        return $this->belongsTo(ParkingSlot::class, 'allocated_slot_id');
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

        return $baseScore - $timePenalty - $vehiclePriority;
    }

    // Kiểm tra có phải giờ cao điểm không
    public function isPeakHour(): bool
    {
        $now = now();
        $peakHours = PeakHour::where('parking_lot_id', $this->parking_lot_id)
            ->where('is_active', true)
            ->get();

        foreach ($peakHours as $peakHour) {
            if ($peakHour->isWithinPeak($now)) {
                return true;
            }
        }

        return false;
    }
}
