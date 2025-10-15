<?php

namespace Database\Seeders;

use App\Models\ParkingSlot;
use App\Models\ParkingZone;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ParkingSlotSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plan = [
            'B1' => [
                ['prefix' => 'B1-MB', 'count' => 80, 'vehicle_type' => 'motorbike'],
                ['prefix' => 'B1-C', 'count' => 40, 'vehicle_type' => 'car_7'],
            ],
            'B2' => [
                ['prefix' => 'B2-MB', 'count' => 70, 'vehicle_type' => 'motorbike'],
                ['prefix' => 'B2-C', 'count' => 30, 'vehicle_type' => 'car_4'],
            ],
            'A' => [
                ['prefix' => 'A-MB', 'count' => 40, 'vehicle_type' => 'motorbike'],
                ['prefix' => 'A-C', 'count' => 20, 'vehicle_type' => 'car_4'],
            ],
        ];

        DB::transaction(function () use ($plan) {
            // Lấy sẵn map zone theo code
            $zones = ParkingZone::whereIn('code', array_keys($plan))
                ->get()->keyBy('code');

            foreach ($plan as $zoneCode => $groups) {
                $zone = $zones->get($zoneCode);
                if (!$zone) {
                    $this->command?->warn("⚠️  Bỏ qua zone {$zoneCode} (chưa có trong DB).");
                    continue;
                }

                foreach ($groups as $g) {
                    $prefix = $g['prefix'];
                    $count = (int) $g['count'];
                    $vtype = $g['vehicle_type'];

                    for ($i = 1; $i <= $count; $i++) {
                        $slotCode = sprintf('%s-%03d', $prefix, $i); // ví dụ: B1-MB-001

                        ParkingSlot::updateOrCreate(
                            ['zone_id' => $zone->id, 'code' => $slotCode], // unique theo (zone_id, code)
                            [
                                'vehicle_type' => $vtype,
                                'status' => 'available',
                                'is_active' => true,
                            ]
                        );
                    }
                }
            }
        });
    }
}
