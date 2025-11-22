<?php

namespace Database\Seeders;

use App\Models\ParkingSlot;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ParkingSlotSeeder extends Seeder
{
    private const VEHICLE_CONFIGS = [
        'motorbike' => ['count' => 150, 'prefix' => 'MB', 'width' => 1.0, 'length' => 2.0, 'spacing' => 0.4, 'cols' => 10, 'rows' => 15],
        'car_4_seat' => ['count' => 100, 'prefix' => 'C4', 'width' => 2.5, 'length' => 5.0, 'spacing' => 0.9, 'cols' => 10, 'rows' => 10],
        'car_7_seat' => ['count' => 40, 'prefix' => 'C7', 'width' => 2.7, 'length' => 5.2, 'spacing' => 1.0, 'cols' => 5, 'rows' => 8],
        'light_truck' => ['count' => 10, 'prefix' => 'LT', 'width' => 3.5, 'length' => 7.0, 'spacing' => 1.1, 'cols' => 5, 'rows' => 2],
    ];

    public function run(): void
    {
        $lot = DB::table('parking_lots')->first();
        if (!$lot) {
            return;
        }

        $lotId = $lot->id;
        $baseLat = $lot->gate_pos_x;
        $baseLng = $lot->gate_pos_y;
        $lotPrefix = $this->extractLotPrefix($lot->name);
        $now = now();
        $slots = [];

        foreach (self::VEHICLE_CONFIGS as $vehicleType => $config) {
            for ($i = 0; $i < $config['count']; $i++) {
                $position = $this->calculateGPSPosition($vehicleType, $i, $baseLat, $baseLng);
                $slotCode = $lotPrefix . '-' . $config['prefix'] . '-' . str_pad($i + 1, 3, '0', STR_PAD_LEFT);

                $slots[] = [
                    'parking_lot_id' => $lotId,
                    'slot_code' => $slotCode,
                    'vehicle_type' => $vehicleType,
                    'status' => 'available',
                    'position_x' => $position['lat'],
                    'position_y' => $position['lng'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        ParkingSlot::insert($slots);
    }

    private function extractLotPrefix(string $lotName): string
    {
        if (preg_match('/B(\d+)/i', $lotName, $matches)) {
            return 'B' . $matches[1];
        }
        return strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $lotName), 0, 3));
    }

    private function calculateGPSPosition(string $vehicleType, int $index, float $baseLat, float $baseLng): array
    {
        $config = self::VEHICLE_CONFIGS[$vehicleType];
        $typeIndex = array_search($vehicleType, array_keys(self::VEHICLE_CONFIGS));

        $gridPosition = $this->calculateGridPosition($vehicleType, $index, $config);
        $verticalOffset = $this->calculateVerticalOffset($typeIndex);

        $offsetFromGate = 10;
        $offsetLat = ($offsetFromGate + $verticalOffset) / 111000;
        $offsetLng = $offsetFromGate / (111000 * cos(deg2rad($baseLat)));

        $slotOffsetX = ($gridPosition['x'] * ($config['width'] + $config['spacing']) + ($config['width'] / 2)) / 111000;
        $slotOffsetY = ($gridPosition['y'] * ($config['length'] + $config['spacing']) + ($config['length'] / 2)) / 111000;

        return [
            'lat' => $baseLat + $offsetLat + $slotOffsetY,
            'lng' => $baseLng + $offsetLng + $slotOffsetX / cos(deg2rad($baseLat)),
        ];
    }

    private function calculateVerticalOffset(int $typeIndex): float
    {
        $configs = array_values(self::VEHICLE_CONFIGS);
        $totalOffset = 0;

        for ($i = 0; $i < $typeIndex; $i++) {
            $config = $configs[$i];
            $totalOffset += $config['rows'] * ($config['length'] + $config['spacing']);
        }

        return $totalOffset;
    }

    private function calculateGridPosition(string $vehicleType, int $index, array $config): array
    {
        $row = intval($index / $config['cols']);
        $col = $index % $config['cols'];
        return ['x' => $col, 'y' => $row];
    }
}
