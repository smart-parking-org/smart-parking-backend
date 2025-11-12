<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ParkingLotSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Tọa độ gốc (bãi đầu tiên)
        $baseLat = 10.806176400733412;
        $baseLng = 106.6286676510779;
        // Khoảng cách giữa các bãi (meters)
        // 1 độ latitude ≈ 111km, 1 độ longitude ≈ 111km * cos(latitude)
        $distanceMeters = 150; // 150m giữa các bãi

        // Chuyển đổi sang độ
        $latOffset = $distanceMeters / 111000; // ~0.00135 độ
        $lngOffset = $distanceMeters / (111000 * cos(deg2rad($baseLat))); // ~0.00135 độ

        DB::table('parking_lots')->insert([
            [
                'name' => 'Bãi đỗ xe Tòa A',
                'gate_pos_x' => $baseLat,
                'gate_pos_y' => $baseLng,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Bãi đỗ xe Tòa B',
                'gate_pos_x' => $baseLat,
                'gate_pos_y' => $baseLng + $lngOffset,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Bãi đỗ xe Tòa C',
                'gate_pos_x' => $baseLat - $latOffset,
                'gate_pos_y' => $baseLng,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
