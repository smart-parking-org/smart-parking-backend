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
        // Tọa độ bãi đỗ xe chung cư (TP.HCM)
        $baseLat = 10.806176400733412;
        $baseLng = 106.6286676510779;

        DB::table('parking_lots')->insert([
            [
                'name' => 'Bãi đỗ xe Tầng hầm B1',
                'gate_pos_x' => $baseLat,
                'gate_pos_y' => $baseLng,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
