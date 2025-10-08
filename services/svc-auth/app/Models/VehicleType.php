<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleType extends Model
{
    protected $fillable = [
        'name',
        'description',
        'code',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];
}
