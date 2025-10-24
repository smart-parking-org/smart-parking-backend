<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PricingRule extends Model
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
     * Lấy cấu hình bảng giá theo key
     */
    public static function getPricingConfig(string $key): ?array
    {
        $config = self::where('key', $key)->first();
        return $config ? $config->value : null;
    }

    /**
     * Cập nhật cấu hình bảng giá
     */
    public static function updatePricingConfig(string $key, array $value): bool
    {
        $config = self::where('key', $key)->first();
        
        if ($config) {
            $config->update(['value' => $value]);
            return true;
        }
        
        return false;
    }

    /**
     * Tạo hoặc cập nhật cấu hình bảng giá
     */
    public static function setPricingConfig(string $key, array $value): self
    {
        return self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /**
     * Lấy giá theo loại xe và thời gian
     */
    public static function getPrice(string $key, string $vehicleType, bool $isPeakHour = false): ?float
    {
        $config = self::getPricingConfig($key);
        
        if (!$config || !isset($config['vehicle_types'][$vehicleType])) {
            return null;
        }

        $vehicleConfig = $config['vehicle_types'][$vehicleType];
        $basePrice = $vehicleConfig['hourly'] ?? 0;
        
        if ($isPeakHour && $vehicleConfig['peak_enabled']) {
            $multiplier = $vehicleConfig['peak_multiplier'] ?? 1.0;
            return $basePrice * $multiplier;
        }
        
        return $basePrice;
    }

    /**
     * Lấy tất cả cấu hình bảng giá
     */
    public static function getAllPricingConfigs(): array
    {
        return self::all()->pluck('value', 'key')->toArray();
    }
}
