<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Google\Client;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    public function loginWithGoogle(Request $request)
    {
        $request->validate([
            'id_token' => 'required|string',
            'role' => 'nullable|in:customer,space_owner',
        ]);

        try {
            $client = new \Google_Client(['client_id' => config('services.google.client_id')]);
            $payload = $client->verifyIdToken($request->id_token);
        } catch (\Throwable $e) {
            $payload = false;
        }

        if (!$payload) {
            return response()->json([
                'message' => 'Google token غير صالح',
            ], 401);
        }

        $googleId = $payload['sub'];
        $email    = $payload['email'];
        $name     = $payload['name'] ?? $email;
        $avatarUrl = $payload['picture'] ?? null;

        $user = User::where('google_id', $googleId)->orWhere('email', $email)->first();

        if ($user) {
            // لو كان مسجل بالإيميل العادي وأول مرة يدخل بقوقل، اربط الحساب
            if (!$user->google_id) {
                $user->update([
                    'google_id' => $googleId,
                    'provider' => 'google',
                    'profile_picture_url' => $user->profile_picture_url ?? $avatarUrl,
                ]);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'تم تسجيل الدخول بنجاح',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'token' => $token,
            ]);
        }

        // A new account is only created when the caller states which role it wants.
        // Defaulting to `customer` here registered a stranger as a real customer
        // the first time they ever signed in with Google, so an unrecognised
        // Google identity now has to say what it is before it becomes an account.
        if (blank($request->input('role'))) {
            return response()->json([
                'code' => 'NOT_REGISTERED',
                'message' => 'هذا الحساب غير مسجّل. يرجى إكمال التسجيل.',
            ], 409);
        }

        $role = $request->input('role');
        $status = $role === 'customer' ? 'active' : 'pending';

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
                'role' => $user->role,
            ],
            'token' => $token,
        ], 200);
    }
}
