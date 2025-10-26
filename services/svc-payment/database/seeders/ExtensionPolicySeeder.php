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
        $now = now();
        ExtensionPolicy::create([
            'key' => 'parking_lot_1_extension_policy',
            'value' => [
                'max_extensions' => 3,           // Cho phép gia hạn tối đa 3 lần
                'extension_minutes' => 15,       // Mỗi lần gia hạn 15 phút
                'is_active' => true,             // Kích hoạt chính sách gia hạn
                'description' => 'Chính sách gia hạn cho bãi đỗ B1 Basement - Cho phép gia hạn tối đa 3 lần, mỗi lần 15 phút',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        ]);
    }
}
