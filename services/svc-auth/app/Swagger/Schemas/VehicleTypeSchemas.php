<?php

namespace App\Swagger\Schemas;

/**
 * @OA\Schema(
 *   schema="VehicleType",
 *   type="object",
 *   required={"id","name","code"},
 *   @OA\Property(property="id", type="integer", example=1),
 *   @OA\Property(property="name", type="string", maxLength=100, example="Xe máy"),
 *   @OA\Property(property="code", type="string", maxLength=20, example="MOTORBIKE"),
 *   @OA\Property(property="description", type="string", nullable=true, example="Dành cho xe máy 2 bánh"),
 *   @OA\Property(property="is_active", type="boolean", example=true),
 *   @OA\Property(property="created_at", type="string", format="date-time", example="2025-10-05T04:30:00Z"),
 *   @OA\Property(property="updated_at", type="string", format="date-time", example="2025-10-05T04:35:00Z")
 * )
 *
 * @OA\Schema(
 *   schema="VehicleTypeCreate",
 *   type="object",
 *   required={"name","code"},
 *   @OA\Property(property="name", type="string", maxLength=100, example="Ô tô 4 chỗ"),
 *   @OA\Property(property="code", type="string", maxLength=20, example="CAR_4"),
 *   @OA\Property(property="description", type="string", nullable=true, example="Xe hơi 4 chỗ ngồi"),
 * )
 *
 * @OA\Schema(
 *   schema="VehicleTypeValidationError",
 *   type="object",
 *   required={"message","errors"},
 *   @OA\Property(property="message", type="string", example="The name field is required. (and 1 more error)"),
 *   @OA\Property(
 *      property="errors",
 *      type="object",
 *      example={
 *        "name": {"The name field is required."},
 *        "code": {"The code field is required."}
 *      }
 *   ),
 * )
 *
 * @OA\Schema(
 *   schema="VehicleTypeUpdate",
 *   type="object",
 *   @OA\Property(property="name", type="string", maxLength=100, example="Ô tô 4 chỗ (updated)"),
 *   @OA\Property(property="code", type="string", maxLength=20, example="CAR_4"),
 *   @OA\Property(property="description", type="string", nullable=true, example="Cập nhật mô tả"),
 *   @OA\Property(property="is_active", type="boolean", example=false)
 * )
 *
 */
class VehicleTypeSchemas
{
}
