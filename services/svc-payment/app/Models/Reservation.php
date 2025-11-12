<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'vehicle_id',
        'slot_id',
        'reservation_request_id',
        'reservation_code',
        'status',
        'start_time',
        'end_time',
        'expires_at',
        'extended_at',
        'check_in_at',
        'check_out_at',
        'cancelled_at',
        'user_snapshot',
        'vehicle_snapshot',
        'pricing_snapshot',
        'extension_count',
        'payment_id',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'expires_at' => 'datetime',
        'extended_at' => 'datetime',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'extension_count' => 'integer',

        'user_snapshot' => 'array',
        'vehicle_snapshot' => 'array',
        'pricing_snapshot' => 'array'
    ];

    // Slot được gán
    public function slot()
    {
        return $this->belongsTo(ParkingSlot::class);
    }

    public function reservationRequest()
    {
        return $this->belongsTo(ReservationRequest::class, 'reservation_request_id');
    }

    // Shortcut để lấy biển số (nếu có)
    public function getPlateAttribute(): ?string
    {
        return $this->vehicle_snapshot['plate'] ?? null;
    }

    // Tính thời lượng đỗ nếu đã check out
    public function getDurationMinutesAttribute(): ?int
    {
        if (!$this->check_in_at || !$this->check_out_at) {
            return null;
        }

        return $this->check_in_at->diffInMinutes($this->check_out_at);
    }

    // Trạng thái kiểm tra nhanh
    public function isActive(): bool
    {
        return in_array($this->status, ['confirmed', 'checked_in', 'pending_checkout']);
    }

    // Relationship với CheckoutCode
    public function checkoutCodes()
    {
        return $this->hasMany(CheckoutCode::class);
    }

    // Relationship với Payment
    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
