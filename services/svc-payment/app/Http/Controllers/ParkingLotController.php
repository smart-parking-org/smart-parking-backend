<?php

namespace App\Http\Controllers;

use App\Models\ParkingLot;
use App\Models\ParkingSlot;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="🏢 Parking Lots",
 *     description="Quản lý thông tin bãi đỗ xe"
 * )
 */
class ParkingLotController extends Controller
{
    /**
     * @OA\Get(
     *     path="/parking-lots",
     *     tags={"🏢 Parking Lots"},
     *     summary="Lấy danh sách tất cả bãi đỗ xe",
     *     description="Trả về danh sách các bãi đỗ hiện có trong hệ thống.",
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
     *     path="/parking-lots/{id}",
     *     tags={"🏢 Parking Lots"},
     *     summary="Lấy chi tiết bãi đỗ xe",
     *     description="Trả về thông tin chi tiết của một bãi đỗ xe, bao gồm danh sách các chỗ đỗ.",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID của bãi đỗ xe",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Thông tin chi tiết bãi đỗ xe",
     *         @OA\JsonContent(ref="#/components/schemas/ParkingLot")
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
    public function show($id)
    {
        $lot = ParkingLot::find($id);

        if (!$lot) {
            return response()->json(['message' => 'Không tìm thấy bãi xe'], 404);
        }

        return response()->json($lot);
    }

    /**
     * @OA\Get(
     *     path="/parking-lots/{id}/slots",
     *     tags={"🏢 Parking Lots"},
     *     summary="Lấy danh sách chỗ đỗ trong một bãi cụ thể",
     *     @OA\Parameter(name="id", in="path", required=true, description="ID bãi đỗ xe", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Danh sách chỗ đỗ trong bãi", @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/ParkingSlot")))
     * )
     */
    public function slotMap($id)
    {
        $slots = ParkingSlot::where('parking_lot_id', $id)->orderBy('id')->get();
        return response()->json($slots);
    }

    /**
     * @OA\Get(
     *     path="/parking-lots/{id}/statistics",
     *     tags={"🏢 Parking Lots"},
     *     summary="Thống kê chi tiết của một bãi đỗ",
     *     description="Trả về số liệu thống kê theo thời gian thực của bãi: số chỗ trống/giữ/đã chiếm, phân bổ theo loại xe, tỷ lệ sử dụng.",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID bãi đỗ xe",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Thống kê bãi đỗ",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="parking_lot_id", type="integer", example=1),
     *             @OA\Property(property="parking_lot_name", type="string", example="Bãi A - Tầng 1"),
     *             @OA\Property(
     *                 property="summary",
     *                 type="object",
     *                 @OA\Property(property="total", type="integer", example=100),
     *                 @OA\Property(property="available", type="integer", example=45),
     *                 @OA\Property(property="hold", type="integer", example=10),
     *                 @OA\Property(property="occupied", type="integer", example=45),
     *                 @OA\Property(
     *                     property="utilization_rate",
     *                     type="number",
     *                     format="float",
     *                     example=55.0,
     *                     description="Tỷ lệ sử dụng (%) = (occupied + hold) / total * 100"
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="by_vehicle_type",
     *                 type="object",
     *                 description="Object với key là mã loại xe (vd: motorbike, car_4_seat, ...).",
     *                 @OA\AdditionalProperties(
     *                     type="object",
     *                     @OA\Property(property="total", type="integer", example=50),
     *                     @OA\Property(property="available", type="integer", example=25),
     *                     @OA\Property(property="hold", type="integer", example=5),
     *                     @OA\Property(property="occupied", type="integer", example=20)
     *                 ),
     *                 example={
     *                   "motorbike": {"total":50,"available":25,"hold":5,"occupied":20},
     *                   "car_4_seat": {"total":30,"available":12,"hold":3,"occupied":15},
     *                   "car_7_seat": {"total":15,"available":4,"hold":1,"occupied":10},
     *                   "light_truck": {"total":5,"available":1,"hold":1,"occupied":3}
     *                 }
     *             ),
     *             @OA\Property(property="last_updated", type="string", format="date-time", example="2025-10-18T14:30:00Z")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy bãi đỗ xe",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Không tìm thấy bãi đỗ xe")
     *         )
     *     )
     * )
     */
    public function statistics($id)
    {
        $parkingLot = ParkingLot::find($id);

        if (!$parkingLot) {
            return response()->json(['message' => 'Không tìm thấy bãi đỗ xe'], 404);
        }

        // Tổng quan
        $slots = ParkingSlot::where('parking_lot_id', $id)->get();

        $summary = [
            'total' => $slots->count(),
            'available' => $slots->where('status', 'available')->count(),
            'hold' => $slots->where('status', 'hold')->count(),
            'occupied' => $slots->where('status', 'occupied')->count(),
        ];

        $summary['utilization_rate'] = $summary['total'] > 0
            ? round(($summary['occupied'] + $summary['hold']) / $summary['total'] * 100, 2)
            : 0;

        // Thống kê theo loại xe
        $vehicleTypes = ['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck'];
        $byVehicleType = [];

        foreach ($vehicleTypes as $type) {
            $typeSlots = $slots->where('vehicle_type', $type);
            $byVehicleType[$type] = [
                'total' => $typeSlots->count(),
                'available' => $typeSlots->where('status', 'available')->count(),
                'hold' => $typeSlots->where('status', 'hold')->count(),
                'occupied' => $typeSlots->where('status', 'occupied')->count(),
            ];
        }

        return response()->json([
            'parking_lot_id' => $parkingLot->id,
            'parking_lot_name' => $parkingLot->name,
            'summary' => $summary,
            'by_vehicle_type' => $byVehicleType,
            'last_updated' => now()->toIso8601String(),
        ]);
    }
}
