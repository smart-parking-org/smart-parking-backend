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
    ];

    public function parkingLot()
    {
        return $this->belongsTo(ParkingLot::class);
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'slot_id');
    }

    public function currentReservation()
    {
        return $this->hasOne(Reservation::class, 'slot_id')
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->latest();
    }

    // Lọc slot khả dụng
    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    // kiểu xe tiếng Việt
    public function getVehicleTypeLabelAttribute(): string
    {
        return match ($this->vehicle_type) {
            'motorbike' => 'Xe máy',
            'car_4_seat' => 'Ô tô 4 chỗ',
            'car_7_seat' => 'Ô tô 7 chỗ',
            'light_truck' => 'Xe tải nhẹ',
            default => ucfirst($this->vehicle_type),
        };
    }

    // Kiểm tra slot có đang bị chiếm không
    public function isOccupied(): bool
    {
        return $this->status === 'occupied';
    }
}
