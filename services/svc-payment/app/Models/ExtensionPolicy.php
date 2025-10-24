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
     * Lấy cấu hình chính sách gia hạn theo key
     */
    public static function getExtensionPolicyConfig(string $key): ?array
    {
        $config = self::where('key', $key)->first();
        return $config ? $config->value : null;
    }

    /**
     * Cập nhật cấu hình chính sách gia hạn
     */
    public static function updateExtensionPolicyConfig(string $key, array $value): bool
    {
        $config = self::where('key', $key)->first();
        
        if ($config) {
            $config->update(['value' => $value]);
            return true;
        }
        
        return false;
    }

    /**
     * Tạo hoặc cập nhật cấu hình chính sách gia hạn
     */
    public static function setExtensionPolicyConfig(string $key, array $value): self
    {
        return self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /**
     * Kiểm tra xem có thể gia hạn không và lấy thông tin gia hạn
     */
    public static function canExtend(string $key, int $currentExtensions = 0): array
    {
        $config = self::getExtensionPolicyConfig($key);
        
        if (!$config) {
            return [
                'can_extend' => false,
                'max_extensions' => 0,
                'extension_minutes' => 0,
                'remaining_extensions' => 0
            ];
        }

        $maxExtensions = $config['max_extensions'] ?? 0;
        $extensionMinutes = $config['extension_minutes'] ?? 0;
        $remainingExtensions = max(0, $maxExtensions - $currentExtensions);

        return [
            'can_extend' => $remainingExtensions > 0,
            'max_extensions' => $maxExtensions,
            'extension_minutes' => $extensionMinutes,
            'remaining_extensions' => $remainingExtensions
        ];
    }

    /**
     * Lấy tất cả cấu hình chính sách gia hạn
     */
    public static function getAllExtensionPolicyConfigs(): array
    {
        return self::all()->pluck('value', 'key')->toArray();
    }
}
