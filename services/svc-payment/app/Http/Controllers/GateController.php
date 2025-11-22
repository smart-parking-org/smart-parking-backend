<?php

namespace App\Http\Controllers;

use App\Models\Gate;
use App\Models\ParkingLot;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="🚪 Gates",
 *     description="Quản lý cổng vào/ra bãi đỗ xe"
 * )
 */
class GateController extends Controller
{
    /**
     * @OA\Get(
     *     path="/parking-lots/{id}/gates",
     *     tags={"🚪 Gates"},
     *     summary="Lấy danh sách cổng theo ID bãi đỗ",
     *     description="Trả về danh sách tất cả cổng (gates) của một bãi đỗ xe cụ thể, chỉ lấy các cổng đang active",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID của bãi đỗ xe",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách cổng của bãi đỗ",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="success",
     *                 type="boolean",
     *                 example=true
     *             ),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="parking_lot_id", type="integer", example=1),
     *                     @OA\Property(property="gate_code", type="string", example="GATE-001"),
     *                     @OA\Property(property="gate_type", type="string", enum={"entrance", "exit", "both"}, example="entrance"),
     *                     @OA\Property(property="position_x", type="number", format="float", nullable=true, example=10.123456789012345),
     *                     @OA\Property(property="position_y", type="number", format="float", nullable=true, example=20.123456789012345),
     *                     @OA\Property(property="is_active", type="boolean", example=true),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy bãi đỗ xe",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Không tìm thấy bãi đỗ xe")
     *         )
     *     )
     * )
     */
    public function getByParkingLot($id)
    {
        $parkingLot = ParkingLot::find($id);

        if (!$parkingLot) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy bãi đỗ xe'
            ], 404);
        }

        $gates = Gate::where('parking_lot_id', $id)
            ->where('is_active', true)
            ->orderBy('gate_code')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $gates
        ]);
    }
}

