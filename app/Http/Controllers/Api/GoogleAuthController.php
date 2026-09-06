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
            'role' => 'required|string'
        ]);

        $client = new Client(['client_id' => config('services.google.client_id')]);
        $payload = $client->verifyIdToken($request->id_token);

        if (!$payload) {
            return response()->json([
                'message' => 'Google token غير صالح'
            ], 401);
        }

        $googleId = $payload['sub'];
        $email = $payload['email'];
        $name = $payload['name'] ?? $email;
        $avatar =  $payload['picture'] ?? null;

        if ($avatar) {
            $profile_picture_url = $avatar->store('profile-pictures', 'cloudinary');
        }

        $user = User::where('googleId', $googleId)->orWhere('email', $email)->first();

        if ($user) {
            $token = $user->createToken('auth_token')->plainTextToken;
            return response()->json([
                'message' => 'تم تسجيل الدخول بنجاح',
                'name' => $user->full_name,
                'token' => $token,
            ]);
        }

        if (!$user) {
            $user = User::create([
                'full_name' => $name,
                'email' => $email,
                'google_id' => $googleId,
                'provider' => 'google',
                'profile_picture_url' => $profile_picture_url,
                'password' => Hash::make(Str::random(24)),
                'role' => $request->role,
                'email_verified_at' => now()
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'تم تسجيل الدخول بنجاح',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'role' => $user->role
                ],
                'token' => $token,
            ]);
        } elseif (!$user->google_id) {
            // مستخدم مسجل بالإيميل العادي، بس أول مرة يستخدم قوقل
            $user->update([
                'google_id' => $googleId,
                'provider' => 'google',
                'profile_picture_url' => $user->profile_picture_url ?? $profile_picture_url,
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'تم تسجيل الدخول بنجاح',
                'name' => $user->full_name,
                'token' => $token,
            ]);
        }
    }
}
