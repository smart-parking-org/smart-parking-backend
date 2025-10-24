<?php

namespace App\Swagger\Schemas;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="PricingRule",
 *     type="object",
 *     title="Pricing Rule",
 *     description="Bảng giá cho các loại xe",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="parking_lot_id", type="integer", example=1),
 *     @OA\Property(property="vehicle_type", type="string", example="car_4_seat", enum={"motorbike", "car_4_seat", "car_7_seat", "light_truck"}),
 *     @OA\Property(property="hourly", type="number", format="float", example=10000, description="Giá mỗi giờ (VND)"),
 *     @OA\Property(property="daily_cap", type="number", format="float", example=100000, description="Giá tối đa mỗi ngày (VND)"),
 *     @OA\Property(property="monthly_pass", type="number", format="float", example=600000, description="Giá vé tháng (VND)"),
 *     @OA\Property(property="peak_enabled", type="boolean", example=true, description="Có áp dụng giờ cao điểm không"),
 *     @OA\Property(property="peak_multiplier", type="number", format="float", example=1.5, description="Hệ số nhân giá giờ cao điểm"),
 *     @OA\Property(property="created_at", type="string", format="datetime", example="2024-01-01T00:00:00.000000Z"),
 *     @OA\Property(property="updated_at", type="string", format="datetime", example="2024-01-01T00:00:00.000000Z"),
 *     @OA\Property(property="parking_lot", ref="#/components/schemas/ParkingLot")
 * )
 */
class PricingRuleSchema
{
    // Schema definition only
}

/**
 * @OA\Schema(
 *     schema="PricingRuleRequest",
 *     type="object",
 *     title="Pricing Rule Request",
 *     description="Dữ liệu tạo/cập nhật bảng giá",
 *     required={"parking_lot_id", "vehicle_type", "hourly"},
 *     @OA\Property(property="parking_lot_id", type="integer", example=1),
 *     @OA\Property(property="vehicle_type", type="string", example="car_4_seat", enum={"motorbike", "car_4_seat", "car_7_seat", "light_truck"}),
 *     @OA\Property(property="hourly", type="number", format="float", example=10000),
 *     @OA\Property(property="daily_cap", type="number", format="float", example=100000),
 *     @OA\Property(property="monthly_pass", type="number", format="float", example=600000),
 *     @OA\Property(property="peak_enabled", type="boolean", example=true),
 *     @OA\Property(property="peak_multiplier", type="number", format="float", example=1.5)
 * )
 */
class PricingRuleRequestSchema
{
    // Schema definition only
}

/**
 * @OA\Schema(
 *     schema="PricingRuleUpdateRequest",
 *     type="object",
 *     title="Pricing Rule Update Request",
 *     description="Dữ liệu cập nhật bảng giá",
 *     @OA\Property(property="hourly", type="number", format="float", example=12000),
 *     @OA\Property(property="daily_cap", type="number", format="float", example=120000),
 *     @OA\Property(property="monthly_pass", type="number", format="float", example=720000),
 *     @OA\Property(property="peak_enabled", type="boolean", example=true),
 *     @OA\Property(property="peak_multiplier", type="number", format="float", example=1.8)
 * )
 */
class PricingRuleUpdateRequestSchema
{
    // Schema definition only
}

