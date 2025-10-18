<?php

namespace Database\Seeders;

use App\Models\ParkingSlot;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ParkingSlotSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();
        // Tạo slots cho các loại xe
        $vehicleTypes = ['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck'];
        $slotCounts = [50, 30, 15, 5]; // Số lượng slot cho mỗi loại xe
        $prefixByType = [
            'motorbike' => 'MB',
            'car_4_seat' => 'C4',
            'car_7_seat' => 'C7',
            'light_truck' => 'LT',
        ];

        $lotId = DB::table('parking_lots')->value('id'); // lấy 1 bãi đầu tiên

        foreach ($vehicleTypes as $index => $vehicleType) {
            $prefix = $prefixByType[$vehicleType];
            for ($i = 0; $i < $slotCounts[$index]; $i++) {
                ParkingSlot::create([
                    'parking_lot_id' => $lotId,
                    'slot_code' => $prefix . '-' . str_pad($i + 1, 3, '0', STR_PAD_LEFT),
                    'vehicle_type' => $vehicleType,
                    'status' => 'available',
                    'position_x' => rand(1, 10),
                    'position_y' => rand(1, 10),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
