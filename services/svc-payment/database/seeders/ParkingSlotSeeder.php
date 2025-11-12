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
        $slotCounts = [150, 100, 35, 15]; // Số lượng slot cho mỗi loại xe
        $prefixByType = [
            'motorbike' => 'MB',
            'car_4_seat' => 'C4',
            'car_7_seat' => 'C7',
            'light_truck' => 'LT',
        ];

        // Lấy tất cả các bãi đỗ xe
        $lots = DB::table('parking_lots')->get(['id', 'name', 'gate_pos_x', 'gate_pos_y']);

        foreach ($lots as $lot) {
            $lotId = $lot->id;
            $gateLat = $lot->gate_pos_x; // Latitude cổng
            $gateLng = $lot->gate_pos_y; // Longitude cổng

            // Tạo prefix từ tên bãi đỗ xe (ví dụ: "Tầng hầm B1" -> "B1")
            $lotPrefix = $this->extractLotPrefix($lot->name);

            // Tạo tất cả slots cho bãi này
            $slots = [];

            foreach ($vehicleTypes as $index => $vehicleType) {
                $prefix = $prefixByType[$vehicleType];
                $slotCount = $slotCounts[$index];

                for ($i = 0; $i < $slotCount; $i++) {
                    // Tính toán tọa độ GPS dựa trên loại xe và vị trí
                    $position = $this->calculateGPSPosition($vehicleType, $i, $gateLat, $gateLng);

                    // Tính khoảng cách từ cổng (meters)
                    $distance = $this->calculateDistance($gateLat, $gateLng, $position['lat'], $position['lng']);

                    // Slot code với prefix của bãi đỗ xe để đảm bảo unique
                    $slotCode = $lotPrefix . '-' . $prefix . '-' . str_pad($i + 1, 3, '0', STR_PAD_LEFT);

                    $slots[] = [
                        'parking_lot_id' => $lotId,
                        'slot_code' => $slotCode,
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

            // Insert slots cho bãi này
            foreach ($slots as $slot) {
                ParkingSlot::create($slot);
            }

            $this->command->info("Created " . count($slots) . " slots for parking lot: {$lot->name}");
        }
    }

    /**
     * Trích xuất prefix từ tên bãi đỗ xe
     * Ví dụ: "Tầng hầm B1" -> "B1", "Bãi đỗ xe Tòa A" -> "TOA-A"
     */
    private function extractLotPrefix($lotName)
    {
        // Nếu tên có dạng "Tầng hầm B1", "Tầng hầm B2", "Tầng hầm B3"
        if (preg_match('/B(\d+)/i', $lotName, $matches)) {
            return 'B' . $matches[1];
        }

        // Nếu tên có dạng "Bãi đỗ xe Tòa A", "Bãi đỗ xe Tòa B"
        if (preg_match('/Tòa\s+([A-Z])/i', $lotName, $matches)) {
            return 'TOA-' . strtoupper($matches[1]);
        }

        // Nếu tên có dạng "Bãi đỗ xe Khu A", "Bãi đỗ xe Khu B"
        if (preg_match('/Khu\s+([A-Z])/i', $lotName, $matches)) {
            return 'KHU-' . strtoupper($matches[1]);
        }

        // Mặc định: lấy 3 ký tự đầu và chuyển thành uppercase
        return strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $lotName), 0, 3));
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
                // Xe máy: 10 hàng x 15 cột = 150 slots
                $row = intval($index / 15);
                $col = ($index % 15);
                return ['x' => $col, 'y' => $row];

            case 'car_4_seat':
                // Ô tô 4 chỗ: 10 hàng x 10 cột = 100 slots
                $row = intval($index / 10) + 10; // Bắt đầu từ hàng 10
                $col = ($index % 10);
                return ['x' => $col, 'y' => $row];

            case 'car_7_seat':
                // Ô tô 7 chỗ: 5 hàng x 7 cột = 35 slots
                $row = intval($index / 7) + 20; // Bắt đầu từ hàng 20
                $col = ($index % 7);
                return ['x' => $col, 'y' => $row];

            case 'light_truck':
                // Xe tải nhẹ: 3 hàng x 5 cột = 15 slots
                $row = intval($index / 5) + 25; // Hàng 25
                $col = ($index % 5);
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
