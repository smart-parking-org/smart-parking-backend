<?php

namespace App\Swagger\Schemas;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="PeakHour",
 *     type="object",
 *     title="Peak Hour",
 *     description="Giờ cao điểm cho bãi đỗ xe",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="parking_lot_id", type="integer", example=1),
 *     @OA\Property(property="day_of_week", type="integer", example=1, description="1=Thứ 2, 2=Thứ 3, ..., 7=Chủ nhật"),
 *     @OA\Property(property="start_time", type="string", example="07:00:00", description="Thời gian bắt đầu (HH:mm:ss)"),
 *     @OA\Property(property="end_time", type="string", example="09:00:00", description="Thời gian kết thúc (HH:mm:ss)"),
 *     @OA\Property(property="is_active", type="boolean", example=true, description="Có đang hoạt động không"),
 *     @OA\Property(property="created_at", type="string", format="datetime", example="2024-01-01T00:00:00.000000Z"),
 *     @OA\Property(property="updated_at", type="string", format="datetime", example="2024-01-01T00:00:00.000000Z"),
 *     @OA\Property(property="parking_lot", ref="#/components/schemas/ParkingLot")
 * )
 */
class PeakHourSchema
{
    // Schema definition only
}

/**
 * @OA\Schema(
 *     schema="PeakHourRequest",
 *     type="object",
 *     title="Peak Hour Request",
 *     description="Dữ liệu tạo giờ cao điểm",
 *     required={"parking_lot_id", "day_of_week", "start_time", "end_time"},
 *     @OA\Property(property="parking_lot_id", type="integer", example=1),
 *     @OA\Property(property="day_of_week", type="integer", example=1, description="1=Thứ 2, 2=Thứ 3, ..., 7=Chủ nhật"),
 *     @OA\Property(property="start_time", type="string", example="07:00:00", description="Thời gian bắt đầu (HH:mm:ss)"),
 *     @OA\Property(property="end_time", type="string", example="09:00:00", description="Thời gian kết thúc (HH:mm:ss)"),
 *     @OA\Property(property="is_active", type="boolean", example=true)
 * )
 */
class PeakHourRequestSchema
{
    // Schema definition only
}

/**
 * @OA\Schema(
 *     schema="PeakHourUpdateRequest",
 *     type="object",
 *     title="Peak Hour Update Request",
 *     description="Dữ liệu cập nhật giờ cao điểm",
 *     @OA\Property(property="day_of_week", type="integer", example=1),
 *     @OA\Property(property="start_time", type="string", example="08:00:00"),
 *     @OA\Property(property="end_time", type="string", example="10:00:00"),
 *     @OA\Property(property="is_active", type="boolean", example=false)
 * )
 */
class PeakHourUpdateRequestSchema
{
    // Schema definition only
}

/**
 * @OA\Schema(
 *     schema="PeakHourCheckResponse",
 *     type="object",
 *     title="Peak Hour Check Response",
 *     description="Kết quả kiểm tra giờ cao điểm",
 *     @OA\Property(property="success", type="boolean", example=true),
 *     @OA\Property(property="is_peak_hour", type="boolean", example=true),
 *     @OA\Property(property="peak_hour_info", ref="#/components/schemas/PeakHour", nullable=true),
 *     @OA\Property(property="checked_datetime", type="string", format="datetime", example="2024-01-01 08:30:00")
 * )
 */
class PeakHourCheckResponseSchema
{
    // Schema definition only
}

