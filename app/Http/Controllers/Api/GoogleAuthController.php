<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    /**
     * Verify a Google ID token and return its claims, or false when invalid.
     *
     * Extracted so the account-linking rules below can be tested without a live
     * Google round-trip.
     */
    protected function verifyIdToken(string $idToken)
    {
        $client = new \Google_Client(['client_id' => config('services.google.client_id')]);

        return $client->verifyIdToken($idToken);
    }

    public function loginWithGoogle(Request $request)
    {
        $request->validate([
            'id_token' => 'required|string',
            'role' => 'nullable|in:customer,space_owner',
        ]);

        try {
            $payload = $this->verifyIdToken($request->id_token);
        } catch (\Throwable $e) {
            $payload = false;
        }

        if (!$payload) {
            return response()->json([
                'message' => 'Google token غير صالح',
            ], 401);
        }

        $googleId = $payload['sub'];
        $email = User::normalizeEmail($payload['email'] ?? null);
        $name = $payload['name'] ?? $email;
        $avatarUrl = $payload['picture'] ?? null;

        // Google only vouches for the address when it says so. Linking an
        // existing password account to a Google identity on an unverified
        // address would let anyone who can put that address on a Google account
        // take over the row.
        $emailVerified = ($payload['email_verified'] ?? false) === true;

        if ($email === null) {
            return response()->json([
                'message' => 'Google token لا يحتوي على بريد إلكتروني صالح',
            ], 401);
        }

        if (! $emailVerified) {
            return response()->json([
                'message' => 'البريد الإلكتروني لحساب Google غير موثّق',
            ], 403);
        }

        // Match on google_id first, then on the normalised email so an account
        // created with email/password is reused instead of duplicated. Email is
        // compared case-insensitively because older rows may not have been
        // normalised when they were written.
        $user = User::where('google_id', $googleId)
            ->orWhereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($user) {
            $updates = [];

            if (! $user->google_id) {
                $updates['google_id'] = $googleId;
                $updates['provider'] = 'google';
            }

            // Persist Google's picture into the same column the upload
            // endpoints write, but never clobber a picture the user already has
            // (an upload, or a picture from a prior Google sign-in).
            if (blank($user->profile_picture_url) && filled($avatarUrl)) {
                $updates['profile_picture_url'] = $avatarUrl;
            }

            if ($updates !== []) {
                $user->update($updates);
            }

            if ($user->status == 'active') {
                $token = $user->createToken('auth_token')->plainTextToken;

                return response()->json([
                    'message' => 'تم تسجيل الدخول بنجاح',
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->full_name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'role' => $user->role,
                        'picture' => $user->pictureUrl(),
                    ],
                    'token' => $token,
                ]);
            }else{
                return response()->json([
                    'message'=> $user->status === 'suspended'
                        ? 'تم تعليق حسابك، يرجى التواصل مع الإدارة'
                        : 'حسابك قيد المراجعة، لا يمكنك تسجيل الدخول حتى تتم الموافقة عليه',
                    'status' => $user->status,
                ], 203);
            }
        }

        if (blank($request->input('role'))) {
            return response()->json([
                'code' => 'NOT_REGISTERED',
                'message' => 'هذا الحساب غير مسجّل. يرجى إكمال التسجيل.',
            ], 409);
        }

        $role = $request->input('role');

        $status = 'active';

        $user = User::create([
            'full_name' => $name,
            'email' => $email,
            'google_id' => $googleId,
            'provider' => 'google',
            'profile_picture_url' => $avatarUrl,
            'password' => Hash::make(Str::random(24)),
            'role' => $role,
            'status' => $status,
            'verified_at' => now(),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'تم تسجيل الدخول بنجاح',
            'user' => [
                'id' => $user->id,
                'name' => $user->full_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'picture' => $user->pictureUrl(),
            ],
            'token' => $token,
        ], 200);
    }
}
