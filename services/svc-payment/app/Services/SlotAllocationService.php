<?php

namespace App\Services;

use App\Models\ReservationRequest;
use App\Models\ParkingSlot;

class SlotAllocationService
{
    /**
     * Factory method để chọn thuật toán cấp chỗ
     */
    public static function allocateSlot(ReservationRequest $request, string $algorithm = 'priority_queue'): ?ParkingSlot
    {
        switch ($algorithm) {
            case 'hungarian':
                return HungarianSlotAllocationService::allocateSlot($request);

            case 'priority_queue':
            default:
                return PriorityQueueSlotAllocationService::allocateSlot($request);
        }
    }

    /**
     * Cấp chỗ với thuật toán hàng đợi ưu tiên
     */
    public static function allocateWithPriorityQueue(ReservationRequest $request): ?ParkingSlot
    {
        return PriorityQueueSlotAllocationService::allocateSlot($request);
    }

    /**
     * Cấp chỗ với thuật toán Hungarian
     */
    public static function allocateWithHungarian(ReservationRequest $request): ?ParkingSlot
    {
        return HungarianSlotAllocationService::allocateSlot($request);
    }
}
