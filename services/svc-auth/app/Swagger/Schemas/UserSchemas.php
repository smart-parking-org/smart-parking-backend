<?php
namespace App\Swagger\Schemas;

/**
 * @OA\Schema(
 *   schema="User",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=1),
 *   @OA\Property(property="name", type="string", example="User Example"),
 *   @OA\Property(property="email", type="string", example="user@gmail.com"),
 *   @OA\Property(property="phone", type="string", example="0919123456"),
 *   @OA\Property(property="role", type="string", example="resident"),
 *   @OA\Property(property="is_active", type="boolean", example=true),
 *   @OA\Property(property="created_at", type="string", format="date-time", example="2025-10-05T04:30:00Z"),
 *   @OA\Property(property="updated_at", type="string", format="date-time", example="2025-10-05T04:30:00Z"),
 *   @OA\Property(property="deleted_at", type="string", format="date-time", example="2025-10-05T04:35:00Z")
 * )
 *
 */
class UserSchemas
{
}
