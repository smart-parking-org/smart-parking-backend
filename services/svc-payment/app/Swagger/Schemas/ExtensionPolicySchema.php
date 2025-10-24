<?php

namespace App\Swagger\Schemas;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="ExtensionPolicy",
 *     type="object",
 *     title="Extension Policy",
 *     description="Chính sách gia hạn cho bãi đỗ xe",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="key", type="string", example="parking_lot_1_extension_policy"),
 *     @OA\Property(property="value", type="object"),
 *     @OA\Property(property="created_at", type="string", format="datetime", example="2024-01-01T00:00:00.000000Z"),
 *     @OA\Property(property="updated_at", type="string", format="datetime", example="2024-01-01T00:00:00.000000Z")
 * )
 */
class ExtensionPolicySchema
{
    // Schema definition only
}

/**
 * @OA\Schema(
 *     schema="ExtensionPolicyRequest",
 *     type="object",
 *     title="Extension Policy Request",
 *     description="Dữ liệu tạo/cập nhật chính sách gia hạn",
 *     required={"key", "value"},
 *     @OA\Property(property="key", type="string", example="parking_lot_1_extension_policy"),
 *     @OA\Property(
 *         property="value",
 *         type="object",
 *         @OA\Property(property="max_extensions", type="integer", example=3, description="Số lần gia hạn tối đa"),
 *         @OA\Property(property="extension_minutes", type="integer", example=30, description="Số phút gia hạn mỗi lần"),
 *         @OA\Property(property="is_active", type="boolean", example=true, description="Có cho phép gia hạn không"),
 *         @OA\Property(property="description", type="string", example="Chính sách gia hạn cho bãi đỗ xe số 1")
 *     )
 * )
 */
class ExtensionPolicyRequestSchema
{
    // Schema definition only
}

/**
 * @OA\Schema(
 *     schema="ExtensionPolicyUpdateRequest",
 *     type="object",
 *     title="Extension Policy Update Request",
 *     description="Dữ liệu cập nhật chính sách gia hạn",
 *     @OA\Property(
 *         property="value",
 *         type="object",
 *         @OA\Property(property="max_extensions", type="integer", example=5, description="Số lần gia hạn tối đa"),
 *         @OA\Property(property="extension_minutes", type="integer", example=60, description="Số phút gia hạn mỗi lần"),
 *         @OA\Property(property="is_active", type="boolean", example=true, description="Có cho phép gia hạn không"),
 *         @OA\Property(property="description", type="string", example="Chính sách gia hạn cập nhật")
 *     )
 * )
 */
class ExtensionPolicyUpdateRequestSchema
{
    // Schema definition only
}

/**
 * @OA\Schema(
 *     schema="ExtensionPolicyCheckResponse",
 *     type="object",
 *     title="Extension Policy Check Response",
 *     description="Kết quả kiểm tra khả năng gia hạn",
 *     @OA\Property(property="success", type="boolean", example=true),
 *     @OA\Property(property="can_extend", type="boolean", example=true),
 *     @OA\Property(property="max_extensions", type="integer", example=3),
 *     @OA\Property(property="extension_minutes", type="integer", example=30),
 *     @OA\Property(property="remaining_extensions", type="integer", example=2),
 *     @OA\Property(property="current_extensions", type="integer", example=1)
 * )
 */
class ExtensionPolicyCheckResponseSchema
{
    // Schema definition only
}
