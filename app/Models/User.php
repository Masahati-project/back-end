<?php

namespace App\Models;

use App\Services\BrevoMailService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Override;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use  HasFactory, Notifiable, HasApiTokens, SoftDeletes;

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
        'google_id',
        'provider',
        'proof_document_url',
        'profile_picture_url',
        'status',
        'verified_at'
    ];

    public static function deleteProofDocument($path)
    {
        if ($path && Storage::disk('cloudinary')->exists($path)) {
            return Storage::disk('cloudinary')->delete($path);
        }
    }
    public static function deletePicture($path)
    {
        if ($path && ! preg_match('#^https?://#i', (string) $path) && Storage::disk('cloudinary')->exists($path)) {
            return Storage::disk('cloudinary')->delete($path);
        }
    }

    /**
     * The public URL for the user's profile picture, or null when there is none.
     *
     * `profile_picture_url` holds two different kinds of value depending on how
     * the picture arrived:
     *
     *  - an uploaded file, stored as a Cloudinary public id (e.g.
     *    "profile-pictures/abc123.jpg");
     *  - a Google account image, which is already an absolute
     *    https://lh3.googleusercontent.com/... URL.
     *
     * Only the first kind may go through the Cloudinary disk; running an
     * absolute URL through `Storage::url()` asks the Cloudinary Admin API to look
     * up an asset whose public id is a URL, which is why the picture disappeared
     * from `GET /api/profile` for some accounts. Both login methods share one
     * column, so the resolver is the single place that knows how to read it.
     */
    public static function resolvePictureUrl(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }

        return Storage::url($value);
    }

    public function pictureUrl(): ?string
    {
        return static::resolvePictureUrl($this->profile_picture_url);
    }

    /**
     * The canonical form of an email address used to match accounts across
     * login methods: trimmed and lower-cased. Google always returns lower-case
     * addresses, while an email/password registration may not, and the two have
     * to land on the same row.
     */
    public static function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = trim($email);

        return $email === '' ? null : mb_strtolower($email);
    }

    #[Override]
    public function sendPasswordResetNotification($token)
    {
        $url = config('app.frontend_url')
            . '/reset-password?token=' . urlencode($token)
            . '&email=' . urlencode($this->email);

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
            'verified_at' => 'datetime',
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
        return $this->belongsToMany(Workspace::class, 'favorites')->withTimestamps();
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

}
