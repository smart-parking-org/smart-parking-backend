<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CheckoutCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'reservation_id',
        'payment_id',
        'checkout_code',
        'status',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    // Relationship với Reservation
    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    // Relationship với Payment
    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    // Kiểm tra QR code còn hiệu lực không
    public function isValid(): bool
    {
        return $this->status === 'active' 
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    // Đánh dấu đã sử dụng
    public function markAsUsed(): void
    {
        $this->update([
            'status' => 'used',
            'used_at' => now(),
        ]);
    }
}

