<?php

namespace App\Http\Controllers;

use App\Models\PeakHour;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Peak Hours",
 *     description="Quản lý giờ cao điểm với cấu trúc key-value"
 * )
 */
class PeakHourController extends Controller
{
    /**
     * @OA\Get(
     *     path="/peak-hours",
     *     summary="Lấy danh sách cấu hình giờ cao điểm",
     *     description="Lấy tất cả cấu hình giờ cao điểm theo key-value",
     *     tags={"Peak Hours"},
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách cấu hình giờ cao điểm",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="key", type="string", example="parking_lot_1_peak_hours"),
     *                 @OA\Property(property="value", type="object"),
     *                 @OA\Property(property="created_at", type="string", format="datetime"),
     *                 @OA\Property(property="updated_at", type="string", format="datetime")
     *             )
     *         )
     *     )
     * )
     */
    public function index(): JsonResponse
    {
        $peakHours = PeakHour::all();

        return response()->json([
            'success' => true,
            'data' => $peakHours
        ]);
    }

    /**
     * @OA\Get(
     *     path="/peak-hours/{key}",
     *     summary="Lấy cấu hình giờ cao điểm theo key",
     *     description="Lấy cấu hình giờ cao điểm của một key cụ thể",
     *     tags={"Peak Hours"},
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         description="Key identifier",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Cấu hình giờ cao điểm",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy cấu hình"
     *     )
     * )
     */
    public function show(string $key): JsonResponse
    {
        $peakHour = PeakHour::where('key', $key)->first();

        if (!$peakHour) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy cấu hình giờ cao điểm'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $peakHour
        ]);
    }

    /**
     * @OA\Post(
     *     path="/peak-hours",
     *     summary="Tạo cấu hình giờ cao điểm mới",
     *     description="Tạo cấu hình giờ cao điểm mới cho một bãi đỗ xe",
     *     tags={"Peak Hours"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"key", "value"},
     *             @OA\Property(property="key", type="string", example="parking_lot_1_peak_hours"),
     *             @OA\Property(
     *                 property="value",
     *                 type="object",
     *                 @OA\Property(
     *                     property="peak_hours",
     *                     type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="day_of_week", type="integer", example=1, description="1=Thứ 2, 2=Thứ 3, ..., 7=Chủ nhật"),
     *                         @OA\Property(property="start_time", type="string", example="07:00:00"),
     *                         @OA\Property(property="end_time", type="string", example="09:00:00"),
     *                         @OA\Property(property="is_active", type="boolean", example=true)
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Tạo thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Tạo cấu hình giờ cao điểm thành công"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dữ liệu không hợp lệ"
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Cấu hình đã tồn tại"
     *     )
     * )
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'key' => 'required|string|unique:peak_hours,key',
                'value' => 'required|array',
                'value.peak_hours' => 'required|array',
                'value.peak_hours.*.day_of_week' => 'required|integer|min:1|max:7',
                'value.peak_hours.*.start_time' => 'required|date_format:H:i:s',
                'value.peak_hours.*.end_time' => 'required|date_format:H:i:s|after:value.peak_hours.*.start_time',
                'value.peak_hours.*.is_active' => 'required|boolean'
            ]);

            $peakHour = PeakHour::create([
                'key' => $validated['key'],
                'value' => $validated['value']
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Tạo cấu hình giờ cao điểm thành công',
                'data' => $peakHour
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ',
                'errors' => $e->errors()
            ], 422);
        }
    }

    /**
     * @OA\Put(
     *     path="/peak-hours/{key}",
     *     summary="Cập nhật cấu hình giờ cao điểm",
     *     description="Cập nhật cấu hình giờ cao điểm theo key (chỉ cho update, không cho tạo mới)",
     *     tags={"Peak Hours"},
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         description="Key identifier",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"value"},
     *             @OA\Property(
     *                 property="value",
     *                 type="object",
     *                 @OA\Property(
     *                     property="peak_hours",
     *                     type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="day_of_week", type="integer", example=1, description="1=Thứ 2, 2=Thứ 3, ..., 7=Chủ nhật"),
     *                         @OA\Property(property="start_time", type="string", example="07:00:00"),
     *                         @OA\Property(property="end_time", type="string", example="09:00:00"),
     *                         @OA\Property(property="is_active", type="boolean", example=true)
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Cập nhật thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Cập nhật cấu hình giờ cao điểm thành công"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy cấu hình"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dữ liệu không hợp lệ"
     *     )
     * )
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $peakHour = PeakHour::where('key', $key)->first();

        if (!$peakHour) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy cấu hình giờ cao điểm'
            ], 404);
        }

        try {
            $validated = $request->validate([
                'value' => 'required|array',
                'value.peak_hours' => 'required|array',
                'value.peak_hours.*.day_of_week' => 'required|integer|min:1|max:7',
                'value.peak_hours.*.start_time' => 'required|date_format:H:i:s',
                'value.peak_hours.*.end_time' => 'required|date_format:H:i:s|after:value.peak_hours.*.start_time',
                'value.peak_hours.*.is_active' => 'required|boolean'
            ]);

            $peakHour->update(['value' => $validated['value']]);

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật cấu hình giờ cao điểm thành công',
                'data' => $peakHour
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ',
                'errors' => $e->errors()
            ], 422);
        }
    }

    /**
     * @OA\Get(
     *     path="/peak-hours/{key}/check",
     *     summary="Kiểm tra giờ cao điểm",
     *     description="Kiểm tra xem một thời điểm có phải giờ cao điểm không",
     *     tags={"Peak Hours"},
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         description="Key identifier",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="datetime",
     *         in="query",
     *         description="Thời điểm cần kiểm tra (Y-m-d H:i:s)",
     *         required=false,
     *         @OA\Schema(type="string", format="datetime")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Kết quả kiểm tra giờ cao điểm",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="is_peak_hour", type="boolean", example=true),
     *             @OA\Property(property="checked_datetime", type="string", format="datetime"),
     *             @OA\Property(property="peak_hour_info", type="object", nullable=true)
     *         )
     *     )
     * )
     */
    public function checkPeakHour(Request $request, string $key): JsonResponse
    {
        $datetime = $request->get('datetime', now());
        
        try {
            $checkTime = Carbon::parse($datetime);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Định dạng thời gian không hợp lệ'
            ], 422);
        }

        $isPeakHour = PeakHour::isPeakHour($key, $checkTime);
        $config = PeakHour::getPeakHourConfig($key);

        return response()->json([
            'success' => true,
            'is_peak_hour' => $isPeakHour,
            'checked_datetime' => $checkTime->toDateTimeString(),
            'peak_hour_info' => $config
        ]);
    }
}