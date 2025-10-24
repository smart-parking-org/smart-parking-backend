<?php

namespace App\Http\Controllers;

use App\Models\ParkingLot;
use App\Models\ParkingSlot;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @OA\Tag(
 *     name="🏢 Parking Lots",
 *     description="Quản lý thông tin bãi đỗ xe"
 * )
 */
class ParkingLotController extends Controller
{
    /** @OA\Get(
     *     path="/parking-lots",
     *     tags={"🏢 Parking Lots"},
     *     summary="Lấy danh sách tất cả bãi đỗ xe",
     *     description="Trả về danh sách các bãi đỗ với thông tin cơ bản và vị trí",
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách bãi đỗ xe",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/ParkingLot"))
     *     )
     * )
     */
    public function index()
    {
        return response()->json(ParkingLot::all());
    }

    /**
     * @OA\Get(
     *     path="/parking-lots/{id}/slots",
     *     tags={"🏢 Parking Lots"},
     *     summary="Lấy danh sách chỗ đỗ trong một bãi cụ thể",
     *     description="Trả về danh sách tất cả slots trong bãi với thông tin chi tiết",
     *     @OA\Parameter(name="id", in="path", required=true, description="ID bãi đỗ xe", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Danh sách chỗ đỗ trong bãi", @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/ParkingSlot"))),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy bãi xe",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Không tìm thấy bãi xe")
     *         )
     *     )
     * )
     */
    public function slotMap($id)
    {
        $lot = ParkingLot::find($id);

        if (!$lot) {
            return response()->json(['message' => 'Không tìm thấy bãi xe'], 404);
        }

        $slots = ParkingSlot::where('parking_lot_id', $id)
            ->withActiveReservations()
            ->select('id', 'slot_code', 'vehicle_type', 'status')
            ->get();

        $summary = [
            'total' => $slots->count(),
            'available' => $slots->where('effective_status', 'available')->count(),
            'hold' => $slots->where('effective_status', 'hold')->count(),
            'occupied' => $slots->where('effective_status', 'occupied')->count(),
        ];

        $vehicleTypes = ['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck'];
        $byVehicleType = [];
        foreach ($vehicleTypes as $type) {
            $typeSlots = $slots->where('vehicle_type', $type);
            $byVehicleType[$type] = [
                'total' => $typeSlots->count(),
                'available' => $typeSlots->where('effective_status', 'available')->count(),
                'hold' => $typeSlots->where('effective_status', 'hold')->count(),
                'occupied' => $typeSlots->where('effective_status', 'occupied')->count(),
            ];
        }

        return response()->json([
            'parking_lot' => [
                'id' => $lot->id,
                'name' => $lot->name,
                'gate_pos_x' => $lot->gate_pos_x,
                'gate_pos_y' => $lot->gate_pos_y
            ],
            'slots' => $slots,
            'summary' => $summary,
            'by_vehicle_type' => $byVehicleType,
            'last_updated' => now()->toIso8601String()
        ]);
    }

    /**
     * @OA\Get(
     *     path="/parking-lots/{id}/statistics",
     *     tags={"🏢 Parking Lots"},
     *     summary="Lấy thống kê chi tiết của bãi đỗ xe",
     *     description="Trả về thống kê tổng quan và theo loại xe của bãi đỗ",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID của bãi đỗ xe",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Thống kê chi tiết bãi đỗ xe",
     *         @OA\JsonContent(ref="#/components/schemas/ParkingLotStatistics")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy bãi xe",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Không tìm thấy bãi xe")
     *         )
     *     )
     * )
     */
    public function statistics($id)
    {
        $lot = ParkingLot::find($id);

        if (!$lot) {
            return response()->json(['message' => 'Không tìm thấy bãi đỗ xe'], 404);
        }

        $slots = ParkingSlot::where('parking_lot_id', $id)
            ->withActiveReservations()
            ->select('vehicle_type', 'status')
            ->get();

        $summary = [
            'total' => $slots->count(),
            'available' => $slots->where('effective_status', 'available')->count(),
            'hold' => $slots->where('effective_status', 'hold')->count(),
            'occupied' => $slots->where('effective_status', 'occupied')->count(),
        ];

        $vehicleTypes = ['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck'];
        $byVehicleType = [];
        foreach ($vehicleTypes as $type) {
            $typeSlots = $slots->where('vehicle_type', $type);
            $byVehicleType[$type] = [
                'total' => $typeSlots->count(),
                'available' => $typeSlots->where('effective_status', 'available')->count(),
                'hold' => $typeSlots->where('effective_status', 'hold')->count(),
                'occupied' => $typeSlots->where('effective_status', 'occupied')->count(),
            ];
        }

        return response()->json([
            'parking_lot_id' => $lot->id,
            'parking_lot_name' => $lot->name,
            'summary' => $summary,
            'by_vehicle_type' => $byVehicleType,
            'last_updated' => now()->toIso8601String(),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/parking-lots/{id}/stream",
     *     tags={"🏢 Parking Lots"},
     *     summary="Stream trạng thái slot theo thời gian thực",
     *     description="Server-Sent Events để cập nhật trạng thái slot liên tục",
     *     @OA\Parameter(name="id", in="path", required=true, description="ID bãi đỗ xe", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Event stream",
     *         @OA\MediaType(mediaType="text/event-stream")
     *     )
     * )
     */
    public function stream($id)
    {
        $lot = ParkingLot::find($id);
        if (!$lot) {
            return response()->json(['message' => 'Không tìm thấy bãi xe'], 404);
        }

        $response = new StreamedResponse(function () use ($lot) {
            @set_time_limit(0);
            @ignore_user_abort(true);

            // Gợi ý client reconnect
            echo "retry: 2000\n\n";
            @ob_flush();
            @flush();

            $lastHash = null;
            $keepAliveCount = 0;

            // Chạy 30 phút (1800 lần * 2s = 3600s)
            for ($i = 0; $i < 1800; $i++) {
                if (connection_aborted()) {
                    break;
                }

                // Query DB
                $slots = ParkingSlot::where('parking_lot_id', $lot->id)
                    ->withActiveReservations()
                    ->select('id', 'slot_code', 'vehicle_type', 'status')
                    ->orderBy('id')
                    ->get();

                $summary = [
                    'total' => $slots->count(),
                    'available' => $slots->where('effective_status', 'available')->count(),
                    'hold' => $slots->where('effective_status', 'hold')->count(),
                    'occupied' => $slots->where('effective_status', 'occupied')->count(),
                ];

                $vehicleTypes = ['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck'];
                $byVehicleType = [];
                foreach ($vehicleTypes as $type) {
                    $typeSlots = $slots->where('vehicle_type', $type);
                    $byVehicleType[$type] = [
                        'total' => $typeSlots->count(),
                        'available' => $typeSlots->where('effective_status', 'available')->count(),
                        'hold' => $typeSlots->where('effective_status', 'hold')->count(),
                        'occupied' => $typeSlots->where('effective_status', 'occupied')->count(),
                    ];
                }

                // Tạo hash từ dữ liệu quan trọng thay vì serialize toàn bộ
                $dataHash = md5(json_encode([
                    'summary' => $summary,
                    'by_vehicle_type' => $byVehicleType,
                    'slots_status' => $slots->pluck('effective_status', 'id')->toArray()
                ]));
                if ($dataHash !== $lastHash) {
                    $payload = [
                        'type' => 'slot_snapshot',
                        'parking_lot' => [
                            'id' => $lot->id,
                            'name' => $lot->name,
                            'gate_pos_x' => $lot->gate_pos_x,
                            'gate_pos_y' => $lot->gate_pos_y
                        ],
                        'slots' => $slots->toArray(), // Convert to array để đảm bảo consistency
                        'summary' => $summary,
                        'by_vehicle_type' => $byVehicleType,
                        'timestamp' => now()->timestamp,
                        'lasted_updated' => now()->toIso8601String()
                    ];

                    echo "event: message\n";
                    echo 'data: ' . json_encode($payload) . "\n\n";
                    @ob_flush();
                    @flush();

                    $lastHash = $dataHash;
                    $keepAliveCount = 0;
                } else {
                    // Gửi keepalive mỗi 10 lần (20 giây) để duy trì connection
                    $keepAliveCount++;
                    if ($keepAliveCount >= 10) {
                        echo ": keepalive\n\n";
                        @ob_flush();
                        @flush();
                        $keepAliveCount = 0;
                    }
                }

                usleep(2000000); // 2s (giảm tải DB)
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        return $response;
    }
}
