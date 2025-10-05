<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleType extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'type_name',
        'description',
        'hourly_rate',
        'daily_rate',
        'monthly_rate',
        'is_active'
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'daily_rate' => 'decimal:2',
        'monthly_rate' => 'decimal:2',
        'is_active' => 'boolean'
    ];
}
