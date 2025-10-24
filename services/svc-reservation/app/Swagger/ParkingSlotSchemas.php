<?php
namespace App\Swagger;


/**
 * @OA\Schema(
 *   schema="ParkingSlot",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=1),
 *   @OA\Property(property="code", type="string", example="A-MB-001"),
 *   @OA\Property(property="vehicle_type", type="string", example="motorbike"),
 *   @OA\Property(property="status", type="string", example="available"),
 *   @OA\Property(property="is_active", type="boolean", example=true),
 *   @OA\Property(
 *    property="zone",
 *    type="object",
 *    @OA\Property(property="id", type="integer", example=1),
 *    @OA\Property(property="name", type="string", example="Basement 1")
 *   ),
 *   @OA\Property(property="created_at", type="string", format="date-time", example="2025-10-05T04:30:00Z"),
 *   @OA\Property(property="updated_at", type="string", format="date-time", example="2025-10-05T04:35:00Z")
 * )
 *
 */
class ParkingSlotSchemas
{
}
