<?php

namespace App\Http\Controllers;

use App\Models\PeakHour;
use App\Models\ParkingLot;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @OA\Tag(
 *     name="⏰ Peak Hours",
 *     description="Quản lý giờ cao điểm cho các bãi đỗ xe"
 * )
 */
class PeakHourController extends Controller
{
    /**
     * @OA\Post(
     *     path="/peak-hours",
     *     tags={"⏰ Peak Hours"},
     *     summary="Tạo giờ cao điểm mới",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"parking_lot_id", "day_of_week", "start_time", "end_time"},
     *             @OA\Property(property="parking_lot_id", type="integer", example=1),
     *             @OA\Property(property="day_of_week", type="integer", example=1, description="0=Chủ nhật, 1=Thứ 2, ..., 6=Thứ 7"),
     *             @OA\Property(property="start_time", type="string", format="time", example="07:00:00"),
     *             @OA\Property(property="end_time", type="string", format="time", example="09:00:00"),
     *             @OA\Property(property="is_active", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Tạo giờ cao điểm thành công",
     *         @OA\JsonContent(ref="#/components/schemas/PeakHour")
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Lỗi validation",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function store(Request $request)
    {
        // Validation trực tiếp trong controller
        $validated = $request->validate([
            'parking_lot_id' => 'required|exists:parking_lots,id',
            'day_of_week' => 'required|integer|min:0|max:6',
            'start_time' => 'required|date_format:H:i:s',
            'end_time' => 'required|date_format:H:i:s|after:start_time',
            'is_active' => 'boolean',
        ]);

        // Kiểm tra conflict thời gian
        $peakHour = new PeakHour($validated);
        if ($peakHour->hasTimeConflict()) {
            return response()->json([
                'message' => 'Khung giờ cao điểm này bị trùng lặp với khung giờ khác',
                'errors' => [
                    'start_time' => ['Khung giờ cao điểm này bị trùng lặp với khung giờ khác'],
                    'end_time' => ['Khung giờ cao điểm này bị trùng lặp với khung giờ khác']
                ]
            ], 422);
        }

        $peakHour = PeakHour::create($validated);

        return response()->json($peakHour->load('parkingLot'), 201);
    }

    /**
     * @OA\Put(
     *     path="/peak-hours/{id}",
     *     tags={"⏰ Peak Hours"},
     *     summary="Cập nhật giờ cao điểm",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID giờ cao điểm",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="day_of_week", type="integer", example=1),
     *             @OA\Property(property="start_time", type="string", format="time", example="07:30:00"),
     *             @OA\Property(property="end_time", type="string", format="time", example="09:30:00"),
     *             @OA\Property(property="is_active", type="boolean", example=false)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Cập nhật thành công",
     *         @OA\JsonContent(ref="#/components/schemas/PeakHour")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy giờ cao điểm",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Không tìm thấy giờ cao điểm")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Lỗi validation"
     *     )
     * )
     */
    public function update(Request $request, $id)
    {
        $peakHour = PeakHour::find($id);

        if (!$peakHour) {
            return response()->json(['message' => 'Không tìm thấy giờ cao điểm'], 404);
        }

        // Validation trực tiếp trong controller
        $validated = $request->validate([
            'day_of_week' => 'sometimes|integer|min:0|max:6',
            'start_time' => 'sometimes|date_format:H:i:s',
            'end_time' => 'sometimes|date_format:H:i:s|after:start_time',
            'is_active' => 'sometimes|boolean',
        ]);

        // Cập nhật model với dữ liệu mới để kiểm tra conflict
        $peakHour->fill($validated);

        // Kiểm tra conflict thời gian
        if ($peakHour->hasTimeConflict()) {
            return response()->json([
                'message' => 'Khung giờ cao điểm này bị trùng lặp với khung giờ khác',
                'errors' => [
                    'start_time' => ['Khung giờ cao điểm này bị trùng lặp với khung giờ khác'],
                    'end_time' => ['Khung giờ cao điểm này bị trùng lặp với khung giờ khác']
                ]
            ], 422);
        }

        $peakHour->update($validated);

        return response()->json($peakHour->load('parkingLot'));
    }

    /**
     * @OA\Delete(
     *     path="/peak-hours/{id}",
     *     tags={"⏰ Peak Hours"},
     *     summary="Xóa giờ cao điểm",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID giờ cao điểm",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Xóa thành công",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Xóa giờ cao điểm thành công")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy giờ cao điểm",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Không tìm thấy giờ cao điểm")
     *         )
     *     )
     * )
     */
    public function destroy($id)
    {
        $peakHour = PeakHour::find($id);

        if (!$peakHour) {
            return response()->json(['message' => 'Không tìm thấy giờ cao điểm'], 404);
        }

        $peakHour->delete();

        return response()->json(['message' => 'Xóa giờ cao điểm thành công']);
    }

    /**
     * @OA\Get(
     *     path="/parking-lots/{parkingLotId}/peak-hours",
     *     tags={"⏰ Peak Hours"},
     *     summary="Lấy giờ cao điểm của một bãi đỗ xe",
     *     @OA\Parameter(
     *         name="parkingLotId",
     *         in="path",
     *         required=true,
     *         description="ID bãi đỗ xe",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách giờ cao điểm của bãi đỗ xe",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="parking_lot", ref="#/components/schemas/ParkingLot"),
     *             @OA\Property(property="peak_hours", type="array", @OA\Items(ref="#/components/schemas/PeakHour"))
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
    public function getByParkingLot($parkingLotId)
    {
        $parkingLot = ParkingLot::find($parkingLotId);

        if (!$parkingLot) {
            return response()->json(['message' => 'Không tìm thấy bãi đỗ xe'], 404);
        }

        $peakHours = PeakHour::forParkingLot($parkingLotId)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'parking_lot' => $parkingLot,
            'peak_hours' => $peakHours
        ]);
    }
}
