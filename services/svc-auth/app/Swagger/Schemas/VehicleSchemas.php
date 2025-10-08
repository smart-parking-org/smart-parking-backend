<?php
namespace App\Swagger\Schemas;

/**
 * @OA\Schema(
 *   schema="Vehicle",
 *   type="object",
 *   required={"id","name","code"},
 *   @OA\Property(property="id", type="integer", example=1),
 *   @OA\Property(
 *     property="user",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="User Example"),
 *     @OA\Property(property="email", type="string", example="user@gmail.com"),
 *     @OA\Property(property="phone", type="string", example="0919123456")
 *   ),
 *   @OA\Property(
 *     property="type",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=4),
 *     @OA\Property(property="name", type="string", example="Xe tải nhẹ"),
 *     @OA\Property(property="code", type="string", example="truck")
 *   ),
 *   @OA\Property(property="license_plate", type="string", maxLength=20, example="94K-123.45"),
 *   @OA\Property(property="is_active", type="boolean", example=true),
 *   @OA\Property(property="is_primary", type="boolean", example=false),
 *   @OA\Property(property="created_at", type="string", format="date-time", example="2025-10-05T04:30:00Z"),
 *   @OA\Property(property="updated_at", type="string", format="date-time", example="2025-10-05T04:35:00Z")
 * )
 *
 */
class VehicleSchemas
{
}
