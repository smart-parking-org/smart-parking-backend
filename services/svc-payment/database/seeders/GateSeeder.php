<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GateSeeder extends Seeder
{
    public function run(): void
    {
        $parkingLot = DB::table('parking_lots')->first();
        if (!$parkingLot) {
            return;
        }

        $baseLat = $parkingLot->gate_pos_x;
        $baseLng = $parkingLot->gate_pos_y;

        $distanceMeters = 80;
        $latOffset = $distanceMeters / 111000;
        $lngOffset = $distanceMeters / (111000 * cos(deg2rad((float) $baseLat)));

        $now = now();
        DB::table('gates')->insert([
            [
                'parking_lot_id' => $parkingLot->id,
                'gate_code' => 'GATE-01',
                'gate_type' => 'both',
                'position_x' => $baseLat,
                'position_y' => $baseLng,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'parking_lot_id' => $parkingLot->id,
                'gate_code' => 'GATE-02',
                'gate_type' => 'both',
                'position_x' => $baseLat + $latOffset,
                'position_y' => $baseLng + $lngOffset,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}

