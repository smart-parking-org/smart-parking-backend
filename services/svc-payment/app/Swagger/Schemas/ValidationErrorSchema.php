<?php

namespace App\Swagger\Schemas;

/**
 * @OA\Schema(
 *     schema="ValidationError",
 *     title="Validation Error Response",
 *     description="Response trả về khi dữ liệu không hợp lệ",
 *     @OA\Property(
 *         property="message",
 *         type="string",
 *         example="The given data was invalid."
 *     ),
 *     @OA\Property(
 *         property="errors",
 *         type="object",
 *         example={
 *             "status": {"The status field is required."}
 *         }
 *     )
 * )
 */
class ValidationErrorSchema
{
}
