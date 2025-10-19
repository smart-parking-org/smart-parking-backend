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
        $hasRequestConflict = ReservationRequest::where('allocated_slot_id', $slotId)
            ->where('status', 'assigned')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('desired_start_time', [$start, $end])
                    ->orWhereBetween(DB::raw('DATE_ADD(desired_start_time, INTERVAL duration_minutes MINUTE)'), [$start, $end])
                    ->orWhere(function ($qq) use ($start, $end) {
                        $qq->where('desired_start_time', '<=', $start)
                            ->whereRaw('DATE_ADD(desired_start_time, INTERVAL duration_minutes MINUTE) >= ?', [$end]);
                    });
            })
            ->exists();

        $hasReservationConflict = Reservation::where('slot_id', $slotId)
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('reserved_at', [$start, $end])
                    ->orWhereBetween('expires_at', [$start, $end])
                    ->orWhere(function ($qq) use ($start, $end) {
                        $qq->where('reserved_at', '<=', $start)
                            ->where('expires_at', '>=', $end);
                    });
            })
            ->exists();

        return $hasRequestConflict || $hasReservationConflict;
    }
}
