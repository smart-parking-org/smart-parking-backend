<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

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

    /**
     * Scope: Lấy các vé tháng đang ACTIVE
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'ACTIVE');
    }

    /**
     * Scope: Lấy vé tháng còn hiệu lực (trong khoảng start_date -> end_date)
     */
    public function scopeValid($query, $date = null)
    {
        $checkDate = $date ? Carbon::parse($date) : Carbon::now();
        
        return $query->where('status', 'ACTIVE')
            ->where(function ($q) use ($checkDate) {
                $q->whereNull('start_date')
                  ->orWhere('start_date', '<=', $checkDate);
            })
            ->where(function ($q) use ($checkDate) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', $checkDate);
            });
    }

    /**
     * Kiểm tra vé tháng có còn hiệu lực không
     */
    public function isValid($date = null): bool
    {
        if ($this->status !== 'ACTIVE') {
            return false;
        }

        $checkDate = $date ? Carbon::parse($date) : Carbon::now();

        // Kiểm tra start_date
        if ($this->start_date && $checkDate->lt($this->start_date)) {
            return false;
        }

        // Kiểm tra end_date
        if ($this->end_date && $checkDate->gt($this->end_date)) {
            return false;
        }

        return true;
    }

    /**
     * Tìm vé tháng active và hợp lệ cho user, vehicle, parking_lot
     * 
     * @param int $userId
     * @param int $vehicleId
     * @param int $parkingLotId
     * @param string|null $date Ngày kiểm tra (mặc định là hôm nay)
     * @return MonthlyPass|null
     */
    public static function findValidPass(
        int $userId,
        int $vehicleId,
        int $parkingLotId,
        ?string $date = null
    ): ?self {
        return self::valid($date)
            ->where('user_id', $userId)
            ->where('vehicle_id', $vehicleId)
            ->where('parking_lot_id', $parkingLotId)
            ->first();
    }
}

