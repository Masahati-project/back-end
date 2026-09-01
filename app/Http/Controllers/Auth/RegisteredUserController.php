<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class RegisteredUserController extends Controller
{
    public function create()
    {
        return view('auth.register');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'phone' => [
                'required',
                'string',
                'regex:/^05(6|9)[0-9]{7}$/',
                'unique:users,phone',
            ],

            'password' => [
                'required',
                'confirmed',
                Rules\Password::defaults(),
            ],
        ]);

        $otp = random_int(100000, 999999);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'otp' => $otp,
        ]);

        return response()->json([
            'message' => 'تم إنشاء الحساب',
            'user_id' => $user->id,
            'otp' => $otp,
        ], 201);
    }
}
