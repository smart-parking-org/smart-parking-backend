<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Violation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'vehicle_id',
        'reservation_id',
        'parking_lot_id',
        'slot_id',
        'type',
        'severity',
        'status',
        'description',
        'fine_amount',
        'evidence_url',
        'ticket_number',
        'resolved_by',
        'resolved_at',
        'resolution_note',
        'violation_time',
        'payment_id',
        'user_snapshot',
        'vehicle_snapshot',
        'reservation_snapshot',
        'meta',
    ];

    protected $casts = [
        'fine_amount' => 'integer',
        'resolved_at' => 'datetime',
        'violation_time' => 'datetime',
        'user_snapshot' => 'array',
        'vehicle_snapshot' => 'array',
        'reservation_snapshot' => 'array',
        'meta' => 'array',
    ];

    // Relationships
    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function parkingLot()
    {
        return $this->belongsTo(ParkingLot::class);
    }

    public function slot()
    {
        return $this->belongsTo(ParkingSlot::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    // Accessors
    public function getPlateAttribute(): ?string
    {
        return $this->vehicle_snapshot['plate'] ?? $this->vehicle_snapshot['license_plate'] ?? null;
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'PENDING');
    }

    public function scopeResolved($query)
    {
        return $query->where('status', 'RESOLVED');
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }
}

