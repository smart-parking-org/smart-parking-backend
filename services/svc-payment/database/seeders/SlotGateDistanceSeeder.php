<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SlotGateDistanceSeeder extends Seeder
{
    private const EARTH_RADIUS = 6371000;

    public function run(): void
    {
        $slots = DB::table('parking_slots')->get(['id', 'position_x', 'position_y']);
        $gates = DB::table('gates')->get(['id', 'position_x', 'position_y']);

        if ($slots->isEmpty() || $gates->isEmpty()) {
            return;
        }

        $distances = [];
        $now = now();

        foreach ($slots as $slot) {
            foreach ($gates as $gate) {
                $distance = $this->calculateDistance(
                    $slot->position_x,
                    $slot->position_y,
                    $gate->position_x,
                    $gate->position_y
                );

                $distances[] = [
                    'slot_id' => $slot->id,
                    'gate_id' => $gate->id,
                    'distance' => round($distance, 2),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('slot_gate_distances')->insert($distances);
    }

    private function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS * $c;
    }
}

