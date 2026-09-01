<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Booking;
use App\Models\Favorite;
use App\Models\StudySession;
use App\Models\StudyGoal;


class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable,HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'image',
        'phone',
          'otp',
            'otp_expires_at',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            //'email_verified_at' => 'datetime',
          //  'password' => 'hashed',
        ];
    }
    public function bookings()
{
    return $this->hasMany(Booking::class);
}

public function favorites()
{
    return $this->hasMany(Favorite::class);
}

public function studySessions()
{
    return $this->hasMany(StudySession::class);
}
public function studyGoal()
{
    return $this->hasOne(StudyGoal::class);
}
}
