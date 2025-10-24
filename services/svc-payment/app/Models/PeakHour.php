<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PeakHour extends Model
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
     * Lấy cấu hình giờ cao điểm theo key
     */
    public static function getPeakHourConfig(string $key): ?array
    {
        $config = self::where('key', $key)->first();
        return $config ? $config->value : null;
    }

    /**
     * Cập nhật cấu hình giờ cao điểm
     */
    public static function updatePeakHourConfig(string $key, array $value): bool
    {
        $config = self::where('key', $key)->first();
        
        if ($config) {
            $config->update(['value' => $value]);
            return true;
        }
        
        return false;
    }

    /**
     * Tạo hoặc cập nhật cấu hình giờ cao điểm
     */
    public static function setPeakHourConfig(string $key, array $value): self
    {
        return self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /**
     * Kiểm tra xem một thời điểm có phải giờ cao điểm không
     */
    public static function isPeakHour(string $key, Carbon $datetime): bool
    {
        $config = self::getPeakHourConfig($key);
        
        if (!$config) {
            return false;
        }

        $dayOfWeek = $datetime->dayOfWeek; // 1=Monday, 7=Sunday
        
        foreach ($config['peak_hours'] ?? [] as $peakHour) {
            if ($peakHour['day_of_week'] == $dayOfWeek && 
                $peakHour['is_active'] &&
                $datetime->format('H:i:s') >= $peakHour['start_time'] &&
                $datetime->format('H:i:s') < $peakHour['end_time']) {
                return true;
            }
        }
        
        return false;
    }
}
