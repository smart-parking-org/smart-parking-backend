<?php

namespace App\Http\Controllers;

use App\Models\ParkingSlot;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="🅿️ Parking Slots",
 *     description="Quản lý & tra cứu chỗ đỗ xe"
 * )
 */
class ParkingSlotController extends Controller
{
    /**
     * @OA\Get(
     *     path="/slots",
     *     tags={"🅿️ Parking Slots"},
     *     summary="Lấy danh sách tất cả chỗ đỗ xe",
     *     description="Có thể lọc theo bãi, loại xe hoặc trạng thái.",
     *     @OA\Parameter(name="parking_lot_id", in="query", description="ID bãi đỗ xe", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="vehicle_type", in="query", description="Loại xe", @OA\Schema(type="string", enum={"motorbike","car_4_seat","car_7_seat","light_truck"})),
     *     @OA\Parameter(name="status", in="query", description="Trạng thái", @OA\Schema(type="string", enum={"available","hold","occupied"})),
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách chỗ đỗ xe",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/ParkingSlot"))
     *     )
     * )
     */
    public function index(Request $r)
    {
        $slots = ParkingSlot::query()
            ->when($r->filled('parking_lot_id'), fn($q) => $q->where('parking_lot_id', $r->parking_lot_id))
            ->when($r->filled('vehicle_type'), fn($q) => $q->where('vehicle_type', $r->vehicle_type))
            ->when($r->filled('status'), fn($q) => $q->where('status', $r->status))
            ->orderBy('id')
            ->get();

        return response()->json($slots);
    }

    /**
     * @OA\Get(
     *     path="/slots/{id}",
     *     tags={"🅿️ Parking Slots"},
     *     summary="Lấy chi tiết một chỗ đỗ xe",
     *     @OA\Parameter(name="id", in="path", required=true, description="ID của chỗ đỗ xe", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Chi tiết chỗ đỗ", @OA\JsonContent(ref="#/components/schemas/ParkingSlot")),
     *     @OA\Response(response=404, description="Không tìm thấy chỗ đỗ xe")
     * )
     */
    public function show($id)
    {
        $slot = ParkingSlot::with('currentReservation')->find($id);
        if (!$slot) {
            return response()->json(['message' => 'Không tìm thấy chỗ đỗ xe'], 404);
        }

        return response()->json($slot);
    }

    /**
     * @OA\Put(
     *     path="/slots/{id}/status",
     *     tags={"🅿️ Parking Slots"},
     *     summary="Cập nhật trạng thái của chỗ đỗ xe (phục vụ mô phỏng hoặc xử lý thủ công)",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID chỗ đỗ xe",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"status"},
     *             @OA\Property(property="status", type="string", enum={"available", "hold", "occupied"}, example="occupied")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Cập nhật thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Cập nhật trạng thái thành công"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dữ liệu không hợp lệ",
     *         @OA\JsonContent(ref="#/components/schemas/ValidationError")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy chỗ đỗ xe",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Không tìm thấy chỗ đỗ xe")
     *         )
     *     )
     * )
     */
    public function updateStatus(Request $r, $id)
    {
        $data = $r->validate([
            'status' => 'required|in:available,hold,occupied',
        ]);

        $slot = ParkingSlot::find($id);
        if (!$slot) {
            return response()->json(['message' => 'Không tìm thấy chỗ đỗ xe'], 404);
        }

        if ($slot->status !== $data['status']) {
            $slot->update([
                'status' => $data['status']
            ]);
        }

        return response()->json(['message' => 'Cập nhật trạng thái thành công', 'data' => $slot]);
    }
}
