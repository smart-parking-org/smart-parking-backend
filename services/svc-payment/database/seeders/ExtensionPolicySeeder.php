<?php

namespace Database\Seeders;

use App\Models\ExtensionPolicy;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ExtensionPolicySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Tạo cấu hình chính sách gia hạn cho parking lot 1
        ExtensionPolicy::create([
            'key' => 'parking_lot_1_extension_policy',
            'value' => [
                'max_extensions' => 3,
                'extension_minutes' => 30,
                'is_active' => true,
                'description' => 'Chính sách gia hạn cho bãi đỗ xe số 1 - tối đa 3 lần, mỗi lần 30 phút'
            ]
        ]);

        // Tạo cấu hình chính sách gia hạn cho parking lot 2
        ExtensionPolicy::create([
            'key' => 'parking_lot_2_extension_policy',
            'value' => [
                'max_extensions' => 5,
                'extension_minutes' => 60,
                'is_active' => true,
                'description' => 'Chính sách gia hạn cho bãi đỗ xe số 2 - tối đa 5 lần, mỗi lần 60 phút'
            ]
        ]);

        // Tạo cấu hình chính sách gia hạn mặc định
        ExtensionPolicy::create([
            'key' => 'default_extension_policy',
            'value' => [
                'max_extensions' => 2,
                'extension_minutes' => 15,
                'is_active' => true,
                'description' => 'Chính sách gia hạn mặc định - tối đa 2 lần, mỗi lần 15 phút'
            ]
        ]);

        // Tạo cấu hình chính sách gia hạn cho VIP
        ExtensionPolicy::create([
            'key' => 'vip_extension_policy',
            'value' => [
                'max_extensions' => 10,
                'extension_minutes' => 120,
                'is_active' => true,
                'description' => 'Chính sách gia hạn VIP - tối đa 10 lần, mỗi lần 120 phút'
            ]
        ]);
    }
}
