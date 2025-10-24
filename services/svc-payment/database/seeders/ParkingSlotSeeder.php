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

        // Tạo slots cho các loại xe với tọa độ thực tế
        $vehicleTypes = ['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck'];
        $slotCounts = [200, 70, 10, 3]; // Số lượng slot cho mỗi loại xe
        $prefixByType = [
            'motorbike' => 'MB',
            'car_4_seat' => 'C4',
            'car_7_seat' => 'C7',
            'light_truck' => 'LT',
        ];

        $lot = DB::table('parking_lots')->first(['id', 'gate_pos_x', 'gate_pos_y']); // lấy 1 bãi đầu tiên
        $lotId = $lot->id; // lấy 1 bãi đầu tiên

        // Tọa độ cổng
        $gateLat = $lot->gate_pos_x; // Latitude cổng
        $gateLng = $lot->gate_pos_y; // Longitude cổng

        // Tạo tất cả slots trước
        $slots = [];

        foreach ($vehicleTypes as $index => $vehicleType) {
            $prefix = $prefixByType[$vehicleType];
            $slotCount = $slotCounts[$index];

            for ($i = 0; $i < $slotCount; $i++) {
                // Tính toán tọa độ GPS dựa trên loại xe và vị trí
                $position = $this->calculateGPSPosition($vehicleType, $i, $gateLat, $gateLng);

                // Tính khoảng cách từ cổng (meters)
                $distance = $this->calculateDistance($gateLat, $gateLng, $position['lat'], $position['lng']);

                $slots[] = [
                    'parking_lot_id' => $lotId,
                    'slot_code' => $prefix . '-' . str_pad($i + 1, 3, '0', STR_PAD_LEFT),
                    'vehicle_type' => $vehicleType,
                    'status' => 'available',
                    'position_x' => $position['lat'], // Latitude
                    'position_y' => $position['lng'], // Longitude
                    'distance_from_gate' => round($distance, 2),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach ($slots as $slot) {
            ParkingSlot::create($slot);
        }
    }

    /**
     * Tính toán tọa độ GPS cho slot dựa trên loại xe
     */
    private function calculateGPSPosition($vehicleType, $index, $gateLat, $gateLng)
    {
        // Kích thước mỗi slot (meters)
        $slotWidth = 2.5; // 2.5m cho xe máy
        $slotLength = 5.0; // 5m cho xe máy

        switch ($vehicleType) {
            case 'motorbike':
                $slotWidth = 2.5;
                $slotLength = 5.0;
                break;
            case 'car_4_seat':
                $slotWidth = 3.0;
                $slotLength = 6.0;
                break;
            case 'car_7_seat':
                $slotWidth = 3.5;
                $slotLength = 7.0;
                break;
            case 'light_truck':
                $slotWidth = 4.0;
                $slotLength = 8.0;
                break;
        }

        // Tính vị trí grid
        $gridPosition = $this->calculateGridPosition($vehicleType, $index);

        // Chuyển đổi grid sang GPS
        $lat = $gateLat + ($gridPosition['y'] * $slotLength / 111000); // 1 degree ≈ 111km
        $lng = $gateLng + ($gridPosition['x'] * $slotWidth / (111000 * cos(deg2rad($gateLat))));

        return ['lat' => $lat, 'lng' => $lng];
    }

    /**
     * Tính toán vị trí grid cho slot
     */
    private function calculateGridPosition($vehicleType, $index)
    {
        switch ($vehicleType) {
            case 'motorbike':
                // Xe máy: 7 hàng x 15 cột = 105 slots
                $row = intval($index / 15);
                $col = ($index % 15);
                return ['x' => $col, 'y' => $row];

            case 'car_4_seat':
                // Ô tô 4 chỗ: 5 hàng x 7 cột = 35 slots
                $row = intval($index / 7) + 7; // Bắt đầu từ hàng 7
                $col = ($index % 7);
                return ['x' => $col, 'y' => $row];

            case 'car_7_seat':
                // Ô tô 7 chỗ: 2 hàng x 4 cột = 8 slots
                $row = intval($index / 4) + 12; // Bắt đầu từ hàng 12
                $col = ($index % 4);
                return ['x' => $col, 'y' => $row];

            case 'light_truck':
                // Xe tải nhẹ: 1 hàng x 2 cột = 2 slots
                $row = 14; // Hàng 14
                $col = $index;
                return ['x' => $col, 'y' => $row];

            default:
                return ['x' => 0, 'y' => 0];
        }
    }

    /**
     * Tính khoảng cách giữa 2 điểm GPS (Haversine formula)
     */
    private function calculateDistance($lat1, $lng1, $lat2, $lng2)
    {
        $earthRadius = 6371000; // Bán kính Trái Đất (meters)

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
