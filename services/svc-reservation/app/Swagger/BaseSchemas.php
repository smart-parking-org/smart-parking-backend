<?php

namespace App\Swagger;

/**
 * @OA\Schema(
 *     schema="Pagination",
 *     type="object",
 *     required={"total","per_page","current_page","last_page"},
 *     @OA\Property(property="total", type="integer", example=50),
 *     @OA\Property(property="per_page", type="integer", example=10),
 *     @OA\Property(property="current_page", type="integer", example=1),
 *     @OA\Property(property="last_page", type="integer", example=5)
 * )
 *
 * @OA\Schema(
 *   schema="ErrorResponse",
 *   type="object",
 *   required={"message"},
 *   @OA\Property(property="message", type="string", example="Đã xảy ra lỗi, vui lòng thử lại sau."),
 * )
 */
class BaseSchemas
{
}
