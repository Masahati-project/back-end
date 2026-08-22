<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\SendOtpNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function registerSpaceOwnerAccount(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|numeric|unique:users,phone',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'proof_document' => 'required|file'
        ]);
        if ($request->hasFile('proof_document')) {
            $file = $request->file('proof_document');
            $path = $file->store('/picture', 'public');

            $user = User::create([
                'full_name' => $request->name,
                'phone' => $request->phone,
                'email' => $request->email,
                'email_verified_at' => null,
                'password' => bcrypt($request->password),
                'role' => 'space_owner',
                'proof_document_url' => $path,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        return response()->json([
            'user' => $user,
            'status' => 201,
            'message' => 'تم تسجيل صاحب المساحة بنجاح, الرجاء الانتظار حتى يتم التحقق من قبل الأدمن'
        ]);
    }

    public function registerCustomerAccount(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|numeric|unique:users,phone',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'full_name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
            'email_verified_at' => null,
            'password' => bcrypt($request->password),
            'role' => 'customer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'user' => $user,
            'status' => 201,
            'message' => 'تم تسجيلك بنجاح'
        ]);
    }
    public function loginAccount(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string'
        ]);

        $loginValue = $request->input('login');
        $loginField = filter_var($loginValue, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        $credentials = [
            $loginField => $loginValue,
            'password' => $request->input('password'),
        ];

        if (Auth::attempt($credentials)) {
            $user = User::where($loginField, $request->login)->first();

            $user->tokens()->delete();

            $token = $user->createToken('auth_token')->plainTextToken;
            return response()->json([
                'message' => 'تم تسجيل دخولك بنجاح',
                'token'   => $token,
                'user'    => $user,
            ], 200);
        }

        return response()->json([
            'message' => 'معلومات خاطئة'
        ], 401);
    }

    public function accountDetails(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'أنت غير مسموح لك بالدخول'
            ], 401);
        }

        return response()->json([
            'user'   => $user
        ], 200);
    }

    public function logoutAccount(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'أنت غير مسموح لك بالدخول'
            ], 401);
        }

        $user->tokens()->delete();

        return response()->json([
            'message' => 'تم تسجيل خروجك بنجاح'
        ], 200);
    }

    public function deleteAccount(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'أنت غير مسوع لك بالدخول'
            ], 401);
        }

        User::deleteProofDocument($user->proof_document_url);
        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => 'تم حذف الحساب بنجاح'
        ], 200);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email'
        ]);
        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => 'تم إرسال رابط إعادة تعيين كلمة السر إلى بريدك الإلكتروني.'
            ], 200);
        }
        return response()->json([
            'message' => 'تعذر إرسال البريد الإلكتروني، يرجى المحاولة لاحقاً.'
        ], 500);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string|min:8|confirmed'
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
            'message' => 'الرمز غير صالح أو انتهت صلاحيته.'
        ], 400);
    }

    public function sendOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            $otp = rand(100000, 999999);

            DB::table('verification_codes')->updateOrInsert(
                ['target' => $request->email],
                [
                    'code' => $otp,
                    'expires_at' => Carbon::now()->addMinute(10),
                    'updated_at' => Carbon::now()
                ]
            );

            $user->notify(new SendOtpNotification($otp));

            return response()->json([
                'message' => 'تم إرسال رمز التأكيد بنجاح.'
            ], 200);
        } else {
            return response()->json([
                'message' => 'هذا الايميل غير مستخدم'
            ], 400);
        }
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6'
        ]);
        $user = User::where('email', $request->email)->first();

        if ($user) {
            $record = DB::table('verification_codes')
                ->where('target', $request->email)
                ->where('code', $request->code)
                ->first();

            if (!$record || Carbon::now()->greaterThan($record->expires_at)) {
                return response()->json([
                    'message' => 'رمز التفعيل غير صحيح أو انتهت صلاحيته.'
                ], 400);
            }

            DB::table('verification_codes')->where('target', $request->email)->delete();

            $user->email_verified_at = Carbon::now();
            $user->save();
        } else {
            return response()->json([
                'message' => 'هذا الحساب غير موجود'
            ], 400);
        }

        return response()->json([
            'message' => 'تم التأكيد بنجاح.'
        ], 200);
    }
}
