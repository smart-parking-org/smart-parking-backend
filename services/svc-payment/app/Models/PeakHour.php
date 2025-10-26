<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *   schema="PeakHour",
 *   type="object",
 *   required={"parking_lot_id", "day_of_week", "start_time", "end_time"},
 *   @OA\Property(property="id", type="integer", example=1),
 *   @OA\Property(property="parking_lot_id", type="integer", example=1),
 *   @OA\Property(property="day_of_week", type="integer", example=1, description="0=Chủ nhật, 1=Thứ 2, ..., 6=Thứ 7"),
 *   @OA\Property(property="start_time", type="string", format="time", example="07:00:00"),
 *   @OA\Property(property="end_time", type="string", format="time", example="09:00:00"),
 *   @OA\Property(property="is_active", type="boolean", example=true),
 *   @OA\Property(property="created_at", type="string", format="date-time"),
 *   @OA\Property(property="updated_at", type="string", format="date-time"),
 *   @OA\Property(property="parking_lot", ref="#/components/schemas/ParkingLot")
 * )
 */
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
     * Accessor để convert UTC sang local time khi lấy dữ liệu
     */
    public function getStartTimeAttribute($value)
    {
        if ($value) {
            return Carbon::parse($value)->setTimezone('Asia/Ho_Chi_Minh')->format('H:i:s');
        }
        return $value;
    }

    public function getEndTimeAttribute($value)
    {
        if ($value) {
            return Carbon::parse($value)->setTimezone('Asia/Ho_Chi_Minh')->format('H:i:s');
        }
        return $value;
    }

    /**
     * Mutator để convert local time sang UTC khi lưu
     */
    public function setStartTimeAttribute($value)
    {
        if ($value) {
            // Nếu là format "H:i:s", tạo datetime với ngày hiện tại và timezone local
            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
                $today = Carbon::today('Asia/Ho_Chi_Minh');
                $time = Carbon::createFromFormat('H:i:s', $value, 'Asia/Ho_Chi_Minh');
                $datetime = $today->setTime($time->hour, $time->minute, $time->second);
                $this->attributes['start_time'] = $datetime->utc();
            } else {
                $this->attributes['start_time'] = $value;
            }
        }
    }

    public function setEndTimeAttribute($value)
    {
        if ($value) {
            // Nếu là format "H:i:s", tạo datetime với ngày hiện tại và timezone local
            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
                $today = Carbon::today('Asia/Ho_Chi_Minh');
                $time = Carbon::createFromFormat('H:i:s', $value, 'Asia/Ho_Chi_Minh');
                $datetime = $today->setTime($time->hour, $time->minute, $time->second);
                $this->attributes['end_time'] = $datetime->utc();
            } else {
                $this->attributes['end_time'] = $value;
            }
        }
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

    public function hasTimeConflict(): bool
    {
        return self::where('parking_lot_id', $this->parking_lot_id)
            ->where('day_of_week', $this->day_of_week)
            ->where('id', '!=', $this->id ?? 0)
            ->where(function ($query) {
                $query->where(function ($q) {
                    // Trường hợp 1: start_time nằm trong khoảng của peak hour khác
                    $q->where('start_time', '<=', $this->start_time)
                        ->where('end_time', '>', $this->start_time);
                })->orWhere(function ($q) {
                    // Trường hợp 2: end_time nằm trong khoảng của peak hour khác
                    $q->where('start_time', '<', $this->end_time)
                        ->where('end_time', '>=', $this->end_time);
                })->orWhere(function ($q) {
                    // Trường hợp 3: peak hour này bao trùm peak hour khác
                    $q->where('start_time', '>=', $this->start_time)
                        ->where('end_time', '<=', $this->end_time);
                });
            })
            ->exists();
    }

    /**
     * Scope để lấy peak hours theo parking lot
     */
    public function scopeForParkingLot($query, $parkingLotId)
    {
        return $query->where('parking_lot_id', $parkingLotId);
    }

    /**
     * Scope để lấy peak hours đang active
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope để lấy peak hours theo ngày trong tuần
     */
    public function scopeForDayOfWeek($query, $dayOfWeek)
    {
        return $query->where('day_of_week', $dayOfWeek);
    }
}
