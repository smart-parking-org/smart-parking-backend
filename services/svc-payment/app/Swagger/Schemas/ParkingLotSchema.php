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
 *     schema="ParkingLotDetail",
 *     type="object",
 *     title="Parking Lot Detail",
 *     description="Thông tin chi tiết bãi đỗ xe, bao gồm danh sách chỗ đỗ",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/ParkingLot"),
 *         @OA\Schema(
 *             @OA\Property(
 *                 property="slots",
 *                 type="array",
 *                 @OA\Items(ref="#/components/schemas/ParkingSlot")
 *             )
 *         )
 *     }
 * )
 */
class ParkingLotSchema
{
}
