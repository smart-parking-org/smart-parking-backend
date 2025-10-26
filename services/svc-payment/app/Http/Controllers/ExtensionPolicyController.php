<?php

namespace App\Http\Controllers;

use App\Models\ExtensionPolicy;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="🔄 Extension Policies",
 *     description="Quản lý chính sách gia hạn đặt chỗ"
 * )
 */
class ExtensionPolicyController extends Controller
{
    /**
     * @OA\Get(
     *     path="/extension-policies/parking-lot/{parkingLotId}",
     *     tags={"🔄 Extension Policies"},
     *     summary="Lấy chính sách gia hạn của một bãi đỗ cụ thể",
     *     description="Trả về chính sách gia hạn cho bãi đỗ xe được chỉ định",
     *     @OA\Parameter(
     *         name="parkingLotId",
     *         in="path",
     *         required=true,
     *         description="ID của bãi đỗ xe",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Chính sách gia hạn của bãi đỗ",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="key", type="string", example="parking_lot_1_extension_policy"),
     *                 @OA\Property(
     *                     property="value",
     *                     type="object",
     *                     @OA\Property(property="max_extensions", type="integer", example=3),
     *                     @OA\Property(property="extension_minutes", type="integer", example=15),
     *                     @OA\Property(property="is_active", type="boolean", example=true),
     *                     @OA\Property(property="description", type="string", example="Chính sách gia hạn cho bãi đỗ B1 Basement")
     *                 ),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy chính sách gia hạn cho bãi đỗ này",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Không tìm thấy chính sách gia hạn cho bãi đỗ này")
     *         )
     *     )
     * )
     */
    public function getByParkingLot(int $parkingLotId)
    {
        $policy = ExtensionPolicy::getForParkingLot($parkingLotId);

        if (!$policy) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy chính sách gia hạn cho bãi đỗ này'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $policy
        ]);
    }

    /**
     * @OA\Put(
     *     path="/extension-policies/{key}",
     *     tags={"🔄 Extension Policies"},
     *     summary="Cập nhật chính sách gia hạn",
     *     description="Cập nhật chính sách gia hạn theo key",
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         required=true,
     *         description="Key của chính sách gia hạn",
     *         @OA\Schema(type="string", example="parking_lot_1_extension_policy")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"value"},
     *             @OA\Property(
     *                 property="value",
     *                 type="object",
     *                 required={"max_extensions","extension_minutes","is_active"},
     *                 @OA\Property(property="max_extensions", type="integer", minimum=0, maximum=10, example=5),
     *                 @OA\Property(property="extension_minutes", type="integer", minimum=1, maximum=1440, example=30),
     *                 @OA\Property(property="is_active", type="boolean", example=true),
     *                 @OA\Property(property="description", type="string", example="Chính sách gia hạn đã được cập nhật")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Cập nhật chính sách gia hạn thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Cập nhật chính sách gia hạn thành công"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="key", type="string", example="parking_lot_1_extension_policy"),
     *                 @OA\Property(
     *                     property="value",
     *                     type="object",
     *                     @OA\Property(property="max_extensions", type="integer", example=5),
     *                     @OA\Property(property="extension_minutes", type="integer", example=30),
     *                     @OA\Property(property="is_active", type="boolean", example=true),
     *                     @OA\Property(property="description", type="string", example="Chính sách gia hạn đã được cập nhật")
     *                 ),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy chính sách gia hạn",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Không tìm thấy chính sách gia hạn")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dữ liệu không hợp lệ",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Dữ liệu không hợp lệ"),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 @OA\Property(property="value.max_extensions", type="array", @OA\Items(type="string", example="Số lần gia hạn tối đa phải từ 0 đến 10"))
     *             )
     *         )
     *     )
     * )
     */
    public function update(Request $request, string $key)
    {
        $policy = ExtensionPolicy::where('key', $key)->firstOrFail();

        $validated = $request->validate([
            'value' => 'required|array',
            'value.max_extensions' => 'required|integer|min:0|max:10',
            'value.extension_minutes' => 'required|integer|min:1|max:1440',
            'value.is_active' => 'required|boolean',
            'value.description' => 'nullable|string',
        ]);

        $policy->update(['value' => $validated['value']]);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật chính sách gia hạn thành công',
            'data' => $policy
        ]);
    }

}
