<?php

namespace Database\Seeders;

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
        $lotId = DB::table('parking_lots')->value('id'); // lấy 1 bãi đầu tiên
        if (!$lotId)
            return;

        $slots = [];

        $makeSlots = function (string $type, int $count, int $startX, int $startY, int $cols, int $gap = 2) use (&$slots, $lotId) {
            $x = $startX;
            $y = $startY;
            for ($i = 1; $i <= $count; $i++) {
                $slots[] = [
                    'parking_lot_id' => $lotId,
                    'slot_code' => strtoupper($type) . '_' . Str::padLeft((string) $i, 3, '0'),
                    'vehicle_type' => $type,
                    'status' => 'available',
                    'position_x' => $x,
                    'position_y' => $y,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                // sắp xếp dạng grid
                if ($i % $cols === 0) {
                    $x = $startX;
                    $y += $gap;
                } else {
                    $x += $gap;
                }
            }
        };

        // Số lượng chỗ
        // Phân bố hợp lý theo diện tích
        $makeSlots('motorbike', 120, 2, 2, 20);
        $makeSlots('car_4_seat', 60, 60, 2, 10);
        $makeSlots('car_7_seat', 20, 60, 20, 10);
        $makeSlots('light_truck', 5, 80, 30, 5);

        foreach (array_chunk($slots, 205) as $chunk) {
            DB::table('parking_slots')->insert($chunk);
        }
    }
}
