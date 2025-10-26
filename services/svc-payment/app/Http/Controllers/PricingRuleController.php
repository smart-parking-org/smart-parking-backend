<?php

namespace App\Http\Controllers;

use App\Models\PricingRule;
use App\Models\ParkingLot;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @OA\Tag(
 *     name="💰 Pricing Rules",
 *     description="Quản lý cấu hình giá cho các bãi đỗ xe"
 * )
 */
class PricingRuleController extends Controller
{
    /**
     * @OA\Put(
     *     path="/pricing-rules/{id}",
     *     tags={"💰 Pricing Rules"},
     *     summary="Cập nhật quy tắc giá",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID quy tắc giá",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="hourly", type="number", format="float", example=6000.00),
     *             @OA\Property(property="daily_cap", type="number", format="float", nullable=true, example=60000.00),
     *             @OA\Property(property="monthly_pass", type="number", format="float", nullable=true, example=900000.00),
     *             @OA\Property(property="peak_enabled", type="boolean", example=true),
     *             @OA\Property(property="peak_multiplier", type="number", format="float", nullable=true, example=1.8)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Cập nhật thành công",
     *         @OA\JsonContent(ref="#/components/schemas/PricingRule")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy quy tắc giá",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Không tìm thấy quy tắc giá")
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
        $pricingRule = PricingRule::find($id);

        if (!$pricingRule) {
            return response()->json(['message' => 'Không tìm thấy quy tắc giá'], 404);
        }

        $validated = $request->validate([
            'hourly' => 'sometimes|numeric|min:0',
            'daily_cap' => 'nullable|numeric|min:0',
            'monthly_pass' => 'nullable|numeric|min:0',
            'peak_enabled' => 'sometimes|boolean',
            'peak_multiplier' => 'nullable|numeric|min:1|max:10',
        ]);

        $pricingRule->update($validated);

        return response()->json($pricingRule->load('parkingLot'));
    }

    /**
     * @OA\Get(
     *     path="/parking-lots/{parkingLotId}/pricing-rules",
     *     tags={"💰 Pricing Rules"},
     *     summary="Lấy quy tắc giá của một bãi đỗ xe",
     *     @OA\Parameter(
     *         name="parkingLotId",
     *         in="path",
     *         required=true,
     *         description="ID bãi đỗ xe",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách quy tắc giá của bãi đỗ xe",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/PricingRule"))
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

        $pricingRules = PricingRule::where('parking_lot_id', $parkingLotId)
            ->get();

        return response()->json([
            'parking_lot' => $parkingLot,
            'pricing_rules' => $pricingRules
        ]);
    }
}
