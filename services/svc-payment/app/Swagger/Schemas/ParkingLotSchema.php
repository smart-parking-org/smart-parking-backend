<?php

namespace App\Swagger\Schemas;

/**
 * @OA\Schema(
 *     schema="ParkingLot",
 *     type="object",
 *     title="Parking Lot",
 *     description="Thông tin cơ bản của một bãi đỗ xe",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Tầng hầm B1"),
 *     @OA\Property(property="gate_pos_x", type="integer", example=10),
 *     @OA\Property(property="gate_pos_y", type="integer", example=20),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-10-18T09:00:00Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-10-18T09:00:00Z")
 * )
 *
 * @OA\Schema(
 *     schema="ParkingLotStatistics",
 *     type="object",
 *     title="Parking Lot Statistics",
 *     description="Thống kê chi tiết bãi đỗ xe",
 *     @OA\Property(property="parking_lot_id", type="integer", example=1),
 *     @OA\Property(property="parking_lot_name", type="string", example="Tầng hầm B1"),
 *     @OA\Property(
 *         property="summary",
 *         type="object",
 *         @OA\Property(property="total", type="integer", example=100),
 *         @OA\Property(property="available", type="integer", example=45),
 *         @OA\Property(property="hold", type="integer", example=15),
 *         @OA\Property(property="occupied", type="integer", example=40),
 *         @OA\Property(property="utilization_rate", type="number", example=55.0)
 *     ),
 *     @OA\Property(
 *         property="by_vehicle_type",
 *         type="object",
 *         @OA\Property(
 *             property="motorbike",
 *             type="object",
 *             @OA\Property(property="total", type="integer", example=50),
 *             @OA\Property(property="available", type="integer", example=25),
 *             @OA\Property(property="hold", type="integer", example=10),
 *             @OA\Property(property="occupied", type="integer", example=15)
 *         ),
 *         @OA\Property(
 *             property="car_4_seat",
 *             type="object",
 *             @OA\Property(property="total", type="integer", example=30),
 *             @OA\Property(property="available", type="integer", example=15),
 *             @OA\Property(property="hold", type="integer", example=5),
 *             @OA\Property(property="occupied", type="integer", example=10)
 *         )
 *     ),
 *     @OA\Property(property="last_updated", type="string", format="date-time", example="2025-10-18T09:00:00Z")
 * )
 */
class ParkingLotSchema
{
}
