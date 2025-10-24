<?php

namespace App\Http\Controllers;

use App\Models\PricingRule;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Pricing Rules",
 *     description="Quản lý bảng giá với cấu trúc key-value"
 * )
 */
class PricingRuleController extends Controller
{
    /**
     * @OA\Get(
     *     path="/pricing-rules",
     *     summary="Lấy danh sách cấu hình bảng giá",
     *     description="Lấy tất cả cấu hình bảng giá theo key-value",
     *     tags={"Pricing Rules"},
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách cấu hình bảng giá",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="key", type="string", example="parking_lot_1_pricing"),
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
        $pricingRules = PricingRule::all();

        return response()->json([
            'success' => true,
            'data' => $pricingRules
        ]);
    }

    /**
     * @OA\Get(
     *     path="/pricing-rules/{key}",
     *     summary="Lấy cấu hình bảng giá theo key",
     *     description="Lấy cấu hình bảng giá của một key cụ thể",
     *     tags={"Pricing Rules"},
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         description="Key identifier",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Cấu hình bảng giá",
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
        $pricingRule = PricingRule::where('key', $key)->first();

        if (!$pricingRule) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy cấu hình bảng giá'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $pricingRule
        ]);
    }

    /**
     * @OA\Put(
     *     path="/pricing-rules/{key}",
     *     summary="Cập nhật cấu hình bảng giá",
     *     description="Cập nhật cấu hình bảng giá theo key (chỉ cho update, không cho tạo mới)",
     *     tags={"Pricing Rules"},
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
     *                     property="vehicle_types",
     *                     type="object",
     *                     @OA\Property(
     *                         property="motorbike",
     *                         type="object",
     *                         @OA\Property(property="hourly", type="number", example=5000),
     *                         @OA\Property(property="daily_cap", type="number", example=50000),
     *                         @OA\Property(property="monthly_pass", type="number", example=300000),
     *                         @OA\Property(property="peak_enabled", type="boolean", example=true),
     *                         @OA\Property(property="peak_multiplier", type="number", example=1.5)
     *                     ),
     *                     @OA\Property(
     *                         property="car_4_seat",
     *                         type="object",
     *                         @OA\Property(property="hourly", type="number", example=10000),
     *                         @OA\Property(property="daily_cap", type="number", example=100000),
     *                         @OA\Property(property="monthly_pass", type="number", example=600000),
     *                         @OA\Property(property="peak_enabled", type="boolean", example=true),
     *                         @OA\Property(property="peak_multiplier", type="number", example=1.5)
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
     *             @OA\Property(property="message", type="string", example="Cập nhật cấu hình bảng giá thành công"),
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
        $pricingRule = PricingRule::where('key', $key)->first();

        if (!$pricingRule) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy cấu hình bảng giá'
            ], 404);
        }

        try {
            $validated = $request->validate([
                'value' => 'required|array',
                'value.vehicle_types' => 'required|array',
                'value.vehicle_types.*.hourly' => 'required|numeric|min:0',
                'value.vehicle_types.*.daily_cap' => 'nullable|numeric|min:0',
                'value.vehicle_types.*.monthly_pass' => 'nullable|numeric|min:0',
                'value.vehicle_types.*.peak_enabled' => 'required|boolean',
                'value.vehicle_types.*.peak_multiplier' => 'nullable|numeric|min:1'
            ]);

            $pricingRule->update(['value' => $validated['value']]);

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật cấu hình bảng giá thành công',
                'data' => $pricingRule
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
     *     path="/pricing-rules/{key}/price",
     *     summary="Lấy giá theo loại xe",
     *     description="Lấy giá theo loại xe và thời gian (có tính giờ cao điểm)",
     *     tags={"Pricing Rules"},
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         description="Key identifier",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="vehicle_type",
     *         in="query",
     *         description="Loại xe",
     *         required=true,
     *         @OA\Schema(type="string", enum={"motorbike", "car_4_seat", "car_7_seat", "light_truck"})
     *     ),
     *     @OA\Parameter(
     *         name="is_peak_hour",
     *         in="query",
     *         description="Có phải giờ cao điểm không",
     *         required=false,
     *         @OA\Schema(type="boolean", default=false)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Giá theo loại xe",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="price", type="number", example=15000),
     *             @OA\Property(property="vehicle_type", type="string", example="car_4_seat"),
     *             @OA\Property(property="is_peak_hour", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy cấu hình hoặc loại xe"
     *     )
     * )
     */
    public function getPrice(Request $request, string $key): JsonResponse
    {
        $vehicleType = $request->get('vehicle_type');
        $isPeakHour = $request->get('is_peak_hour', false);

        if (!$vehicleType) {
            return response()->json([
                'success' => false,
                'message' => 'Thiếu tham số vehicle_type'
            ], 422);
        }

        $price = PricingRule::getPrice($key, $vehicleType, $isPeakHour);

        if ($price === null) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy giá cho loại xe này'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'price' => $price,
            'vehicle_type' => $vehicleType,
            'is_peak_hour' => $isPeakHour
        ]);
    }
}