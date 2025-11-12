<?php

namespace App\Swagger\Schemas;

/**
 * @OA\Schema(
 *   schema="Violation",
 *   type="object",
 *   required={"type", "severity", "status"},
 *   @OA\Property(property="id", type="integer", example=1),
 *   @OA\Property(property="user_id", type="integer", nullable=true, example=1),
 *   @OA\Property(property="vehicle_id", type="integer", nullable=true, example=10),
 *   @OA\Property(property="reservation_id", type="integer", nullable=true, example=5),
 *   @OA\Property(property="parking_lot_id", type="integer", nullable=true, example=2),
 *   @OA\Property(property="slot_id", type="integer", nullable=true, example=15),
 *   @OA\Property(property="type", type="string", enum={"OVERSTAY", "LATE_CHECK_IN", "PARKING_EXPIRED_CHECK_IN", "NO_SHOW", "LATE_PAYMENT", "WRONG_SLOT", "NO_RESERVATION", "OTHER"}, example="OVERSTAY"),
 *   @OA\Property(property="severity", type="string", enum={"LOW", "MEDIUM", "HIGH", "CRITICAL"}, example="MEDIUM"),
 *   @OA\Property(property="status", type="string", enum={"PENDING", "RESOLVED", "CANCELLED", "APPEALED"}, example="PENDING"),
 *   @OA\Property(property="description", type="string", nullable=true, example="Đỗ xe quá giờ 30 phút so với thời gian đã đặt"),
 *   @OA\Property(property="fine_amount", type="integer", nullable=true, example=50000, description="Tiền phạt (VND)"),
 *   @OA\Property(property="evidence_url", type="string", nullable=true, example="https://example.com/evidence/image.jpg"),
 *   @OA\Property(property="ticket_number", type="string", nullable=true, example="VP-20251030-00123"),
 *   @OA\Property(property="resolved_by", type="integer", nullable=true, example=1, description="Admin ID xử lý"),
 *   @OA\Property(property="resolved_at", type="string", format="date-time", nullable=true),
 *   @OA\Property(property="resolution_note", type="string", nullable=true, example="Đã thanh toán phạt"),
 *   @OA\Property(property="violation_time", type="string", format="date-time", nullable=true),
 *   @OA\Property(property="payment_id", type="integer", nullable=true, example=100),
 *   @OA\Property(property="user_snapshot", type="object", nullable=true),
 *   @OA\Property(property="vehicle_snapshot", type="object", nullable=true),
 *   @OA\Property(property="reservation_snapshot", type="object", nullable=true),
 *   @OA\Property(property="meta", type="object", nullable=true),
 *   @OA\Property(property="created_at", type="string", format="date-time"),
 *   @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class ViolationSchema
{
}

