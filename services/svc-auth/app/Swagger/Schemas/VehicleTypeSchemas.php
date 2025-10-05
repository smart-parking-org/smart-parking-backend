<?php

namespace App\Swagger\Schemas;

/**
 * @OA\Schema(
 *   schema="VehicleType",
 *   type="object",
 *   required={"id","type_name"},
 *   @OA\Property(property="id", type="integer", example=1),
 *   @OA\Property(property="type_name", type="string", maxLength=100, example="Car"),
 *   @OA\Property(property="description", type="string", nullable=true, example="Standard 4-seater"),
 *   @OA\Property(property="hourly_rate", type="number", format="float", nullable=true, example=1.50),
 *   @OA\Property(property="daily_rate", type="number", format="float", nullable=true, example=20.00),
 *   @OA\Property(property="monthly_rate", type="number", format="float", nullable=true, example=200.00),
 *   @OA\Property(property="created_at", type="string", format="date-time", example="2025-10-05T04:30:00Z"),
 *   @OA\Property(property="updated_at", type="string", format="date-time", example="2025-10-05T04:35:00Z")
 * )
 *
 * @OA\Schema(
 *   schema="VehicleTypeCreate",
 *   type="object",
 *   required={"type_name"},
 *   @OA\Property(property="type_name", type="string", maxLength=100, example="Motorbike"),
 *   @OA\Property(property="description", type="string", nullable=true, example="2 wheels"),
 *   @OA\Property(property="hourly_rate", type="number", format="float", nullable=true, example=0.50),
 *   @OA\Property(property="daily_rate", type="number", format="float", nullable=true, example=7.00),
 *   @OA\Property(property="monthly_rate", type="number", format="float", nullable=true, example=70.00)
 * )
 *
 * @OA\Schema(
 *   schema="VehicleTypeUpdate",
 *   type="object",
 *   @OA\Property(property="type_name", type="string", maxLength=100, example="Motorbike (updated)"),
 *   @OA\Property(property="description", type="string", nullable=true, example="Updated desc"),
 *   @OA\Property(property="hourly_rate", type="number", format="float", nullable=true, example=0.60),
 *   @OA\Property(property="daily_rate", type="number", format="float", nullable=true, example=8.00),
 *   @OA\Property(property="monthly_rate", type="number", format="float", nullable=true, example=80.00)
 * )
 */
class VehicleTypeSchemas
{
}
