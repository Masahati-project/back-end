<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Services\BrevoMailService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Override;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use  HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'full_name',
        'phone',
        'email',
        'password',
        'role',
        'proof_document_url',
        'profile_picture_url',
        'status',
        'email_verified_at'
    ];

    public static function deleteProofDocument($path)
    {
        if ($path && Storage::disk('cloudinary')->exists($path)) {
            return Storage::disk('cloudinary')->delete($path);
        }
    }
    public static function deletePicture($path)
    {
        if ($path && Storage::disk('cloudinary')->exists($path)) {
            return Storage::disk('cloudinary')->delete($path);
        }
    }

    #[Override]
    public function sendPasswordResetNotification($token)
    {
        $url = url(config('app.frontend_url') . '/reset-password?token=' . $token . '&email=' . urlencode($this->email));

        BrevoMailService::sendHtmlMail(
            toEmail : $this->email,
            toName : $this->full_name,
            subject : 'إعادة تعيين كلمة المرور - مساحاتي',
            view : 'email.reset-password',
            data : [
                'name' => $this->full_name,
                'url' => $url,
                'expire' => config('auth.password.' . config('auth.defaults.passwords') . '.expire', 60),
            ]
        );
    }
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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_id');
    }

    public function spaceOwnerVerification(): HasOne
    {
        return $this->hasOne(SpaceOwnerVerification::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'owner_id');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function favoriteWorkspaces(): BelongsToMany
    {
        return $this->belongsToMany(WorkSpace::class, 'favorites')->using(Favorite::class)->withTimestamps();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->where('status', 'active')
            ->latestOfMany();
    }

    // public function getTotalCompletedHoursAttribute(): float
    // {
    //     $totalMinutes = $this->bookings()
    //         ->where('status', 'completed')
    //         ->selectRaw('SUM(TIMESTAMPDIFF(MINUTE, start_datetime, end_datetime)) as total_minutes')
    //         ->value('total_minutes');

    //     return round(($totalMinutes ?? 0) / 60, 2);
    // }
}
