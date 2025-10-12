<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccountStatus;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'apartment_code',
        'password',
        'status',
        'approved_by',
        'approved_at',
        'rejected_reason',
        'role'
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => AccountStatus::class
        ];
    }

    public function approver()
    {
        return $this->belongsTo(self::class, 'approved_by');
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }
}
