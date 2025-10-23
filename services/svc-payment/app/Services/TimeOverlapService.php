<?php
namespace App\Services;

use Carbon\Carbon;
use App\Models\Reservation;
use App\Models\ReservationRequest;
use Illuminate\Support\Facades\DB;

class TimeOverlapService
{
    // Trả về true nếu slot bị bận trong [start, end]
    public static function hasOverlapOnSlot(int $slotId, Carbon $start, Carbon $end): bool
    {
        $conflict = Reservation::where('slot_id', $slotId)
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->where(function ($query) use ($start, $end) {
                $query
                    // Case 1: Reservation mới bắt đầu trong khoảng đã đặt
                    ->whereBetween('start_time', [$start, $end])
                    // Case 2: Reservation mới kết thúc trong khoảng đã đặt
                    ->orWhereBetween('end_time', [$start, $end])
                    // Case 3: Reservation mới bao trùm reservation cũ
                    ->orWhere(function ($q) use ($start, $end) {
                        $q->where('start_time', '<=', $start)
                            ->where('end_time', '>=', $end);
                    })
                    // Case 4: Reservation cũ bao trùm reservation mới
                    ->orWhere(function ($q) use ($start, $end) {
                        $q->where('start_time', '>=', $start)
                            ->where('end_time', '<=', $end);
                    });
            })
            ->exists();

        return $conflict;
    }
}
