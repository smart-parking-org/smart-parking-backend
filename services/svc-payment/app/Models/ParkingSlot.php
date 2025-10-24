<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParkingSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'parking_lot_id',
        'slot_code',
        'vehicle_type',
        'status',
        'position_x',
        'position_y',
        'distance_from_gate'
    ];

    protected $appends = ['effective_status'];

    public function parkingLot()
    {
        return $this->belongsTo(ParkingLot::class);
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'slot_id');
    }

    public function reservationRequests()
    {
        return $this->hasMany(ReservationRequest::class, 'allocated_slot_id');
    }

    public function currentReservation()
    {
        return $this->hasOne(Reservation::class, 'slot_id')
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->latest();
    }

    public function scopeWithActiveReservations($query)
    {
        $now = now();
        return $query->with([
            'currentReservation' => function ($query) use ($now) {
                $query->where('status', 'confirmed')
                    ->where('start_time', '<=', $now)
                    ->where('expires_at', '>=', $now);
            }
        ]);
    }

    // Trạng thái thực tế: nếu có reservation hiện tại → hold, ngược lại dùng status của slot
    public function getEffectiveStatusAttribute(): string
    {
        $now = now();

        // Lấy reservation một lần
        $reservation = $this->currentReservation;

        // Kiểm tra reservation có tồn tại và hợp lệ không
        if (
            $reservation &&
            $reservation->status === 'confirmed' &&
            $reservation->start_time &&
            $reservation->expires_at &&
            $now->between($reservation->start_time, $reservation->expires_at)
        ) {
            return 'hold';
        }


        return $this->status;
    }
}
