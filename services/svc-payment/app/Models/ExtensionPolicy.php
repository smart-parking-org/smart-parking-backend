<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExtensionPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    /**
     * Lấy extension policy cho parking lot
     */
    public static function getForParkingLot(int $parkingLotId): ?self
    {
        $key = "parking_lot_{$parkingLotId}_extension_policy";
        return self::where('key', $key)->first();
    }

    /**
     * Kiểm tra có thể gia hạn không
     */
    public function canExtend(int $currentExtensions = 0): bool
    {
        if (!$this->value['is_active']) {
            return false;
        }

        return $currentExtensions < $this->value['max_extensions'];
    }

    /**
     * Lấy số phút gia hạn
     */
    public function getExtensionMinutes(): int
    {
        return $this->value['extension_minutes'] ?? 15;
    }
}
