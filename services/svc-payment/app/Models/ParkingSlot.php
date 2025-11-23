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

    public function gateDistances()
    {
        return $this->hasMany(SlotGateDistance::class, 'slot_id');
    }

    public function gates()
    {
        return $this->belongsToMany(Gate::class, 'slot_gate_distances')
            ->withPivot('distance')
            ->withTimestamps();
    }

    public function currentReservation()
    {
        return $this->hasOne(Reservation::class, 'slot_id')
            ->whereIn('status', ['checked_in', 'pending_checkout'])
            ->latest();
    }

    public function scopeWithActiveReservations($query)
    {
        return $query->with([
            'currentReservation' => function ($query) {
                $now = now();
                $query->whereIn('status', ['checked_in', 'pending_checkout'])
                    ->where('start_time', '<=', $now)
                    ->where('end_time', '>=', $now);
            }
        ]);
    }

    // Trạng thái thực tế: nếu có reservation checked_in/pending_checkout → occupied, ngược lại available
    public function getEffectiveStatusAttribute(): string
    {
        $now = now();

        // Lấy reservation hiện tại (chỉ checked_in hoặc pending_checkout)
        $reservation = $this->currentReservation;

        // Nếu có reservation active → occupied
        if ($reservation) {
            return 'occupied';
        }

        // Không có reservation → available
        return 'available';
    }
}
