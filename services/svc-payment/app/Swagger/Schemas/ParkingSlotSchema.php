<?php

namespace App\Swagger\Schemas;

/**
 * @OA\Schema(
 *     schema="ParkingSlot",
 *     type="object",
 *     title="Parking Slot",
 *     description="Thông tin một chỗ đỗ trong bãi xe",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="slot_code", type="string", example="B1-001"),
 *     @OA\Property(property="vehicle_type", type="string", example="motorbike"),
 *     @OA\Property(property="status", type="string", example="available"),
 *     @OA\Property(property="position_x", type="integer", example=5),
 *     @OA\Property(property="position_y", type="integer", example=8),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-10-18T09:00:00Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-10-18T09:00:00Z")
 * )
 */
class ParkingSlotSchema
{
}
