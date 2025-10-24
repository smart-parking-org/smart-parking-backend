<?php
namespace App\Swagger;


/**
 * @OA\Schema(
 *   schema="ParkingZone",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=1),
 *   @OA\Property(property="code", type="string", example="B1"),
 *   @OA\Property(property="name", type="string", example="Basement 1"),
 *   @OA\Property(property="capacity", type="integer", nullable=true, example=120),
 *   @OA\Property(property="description", type="string", nullable=true, example="Khu hầm B1"),
 *   @OA\Property(property="is_active", type="boolean", example=true),
 *   @OA\Property(property="created_at", type="string", format="date-time", example="2025-10-05T04:30:00Z"),
 *   @OA\Property(property="updated_at", type="string", format="date-time", example="2025-10-05T04:35:00Z")
 * )
 *
 */
class ParkingZoneSchemas
{
}
