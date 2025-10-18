<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PeakHour extends Model
{
    use HasFactory;

    protected $fillable = [
        'parking_lot_id',
        'day_of_week',
        'start_time',
        'end_time',
        'is_active',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Quan hệ: PeakHour thuộc về một ParkingLot
     */
    public function parkingLot()
    {
        return $this->belongsTo(ParkingLot::class);
    }

    /**
     * Kiểm tra nếu thời gian truyền vào có nằm trong khung cao điểm
     */
    public function isWithinPeak(Carbon $time): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $dayMatch = $time->dayOfWeek === $this->day_of_week;
        $inTime = $time->format('H:i:s') >= $this->start_time &&
            $time->format('H:i:s') < $this->end_time;

        return $dayMatch && $inTime;
    }
}
