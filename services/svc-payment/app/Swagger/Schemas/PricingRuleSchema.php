<?php

namespace App\Swagger\Schemas;

/**
 * @OA\Schema(
 *   schema="PricingRule",
 *   type="object",
 *   required={"parking_lot_id", "vehicle_type", "hourly"},
 *   @OA\Property(property="id", type="integer", example=1),
 *   @OA\Property(property="parking_lot_id", type="integer", example=1),
 *   @OA\Property(property="vehicle_type", type="string", enum={"motorbike", "car_4_seat", "car_7_seat", "light_truck"}, example="car_4_seat"),
 *   @OA\Property(property="hourly", type="number", format="float", example=5000.00),
 *   @OA\Property(property="daily_cap", type="number", format="float", nullable=true, example=50000.00),
 *   @OA\Property(property="monthly_pass", type="number", format="float", nullable=true, example=800000.00),
 *   @OA\Property(property="peak_enabled", type="boolean", example=true),
 *   @OA\Property(property="peak_multiplier", type="number", format="float", nullable=true, example=1.5),
 *   @OA\Property(property="created_at", type="string", format="date-time"),
 *   @OA\Property(property="updated_at", type="string", format="date-time"),
 *   @OA\Property(property="parking_lot", ref="#/components/schemas/ParkingLot")
 * )
 */
class PricingRuleSchema
{
}
