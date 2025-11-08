<?php

namespace App\Swagger\Schemas;

/**
 * @OA\Schema(
 *   schema="MonthlyPass",
 *   type="object",
 *   required={"user_id", "vehicle_id", "parking_lot_id", "amount", "status", "order_id"},
 *   @OA\Property(property="id", type="integer", example=1),
 *   @OA\Property(property="user_id", type="integer", example=1),
 *   @OA\Property(property="vehicle_id", type="integer", example=10),
 *   @OA\Property(property="parking_lot_id", type="integer", example=2),
 *   @OA\Property(property="months", type="integer", example=1, description="Số tháng"),
 *   @OA\Property(property="start_date", type="string", format="date", nullable=true, example="2025-11-01"),
 *   @OA\Property(property="end_date", type="string", format="date", nullable=true, example="2025-11-30"),
 *   @OA\Property(property="amount", type="integer", example=1800000, description="Số tiền (VND)"),
 *   @OA\Property(property="status", type="string", enum={"PENDING", "ACTIVE", "CANCELLED", "EXPIRED", "FAILED"}, example="PENDING"),
 *   @OA\Property(property="order_id", type="string", example="MP-20251029120000-12345"),
 *   @OA\Property(property="txn_ref", type="string", nullable=true, example="ORD20251029120000123"),
 *   @OA\Property(property="user_snapshot", type="object", nullable=true, description="Thông tin user cache từ svc-auth"),
 *   @OA\Property(property="vehicle_snapshot", type="object", nullable=true, description="Thông tin vehicle cache từ svc-auth"),
 *   @OA\Property(property="created_at", type="string", format="date-time"),
 *   @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class MonthlyPassSchema
{
}

