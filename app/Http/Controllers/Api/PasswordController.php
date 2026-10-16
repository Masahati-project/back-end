<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;

class PasswordController extends Controller
{
    /**
     * Always answers 200, whether or not the address has an account.
     *
     * The `exists:users,email` rule this replaced answered 422 for an unknown
     * address, which is a free oracle for finding out which email addresses are
     * registered. Since the login form takes either an email or a phone number,
     * that oracle turns "who has an account here" into one request per guess.
     *
     * Malformed input is still rejected with 422: "not a valid email" leaks
     * nothing about the database, whereas "no such user" does.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'تم إرسال رابط إعادة تعيين كلمة السر إلى بريدك الإلكتروني.',
        ], 200);
    }

    /**
     * Consumes a reset token.
     *
     * Every failure comes back as 422 under a named field — `token` for an
     * expired or unknown token, the password rules for a rejected new password —
     * so the form can put the message on the right input. A bare 400/500 tells
     * the user nothing they can act on, and a 500 on a malformed body is how
     * this endpoint used to fail.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/'
            ]
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->setRememberToken(Str::random(60));

                $user->save();
                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'تم تغيير كلمة السر بنجاح.'
            ], 200);
        }

        return response()->json([
            'message' => 'الرمز غير صالح أو انتهت صلاحيته.',
            'errors' => [
                'token' => ['الرمز غير صالح أو انتهت صلاحيته.'],
            ],
        ], 422);
    }
}