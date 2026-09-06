<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback()
    {
        $googleUser = Socialite::driver('google')->user();

        $user = User::where('email', $googleUser->getEmail())->first();

        if (!$user) {

            $user = User::create([
                'name' => $googleUser->getName(),
                'email' => $googleUser->getEmail(),
                'password' => bcrypt(str()->random(16)),
            ]);
        }

        Auth::login($user);

        return response()->json([
            'message' => 'Google login successful',
            'user' => $user,
        ]);
    }
}