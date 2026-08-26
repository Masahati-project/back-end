<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BrevoMailService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
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
            $request->merge([
                'proof_document_url' => $path,
            ]);
        }

        $otp = rand(100000, 999999);
        $token = Str::uuid()->toString();
        Cache::put('pending_registration_' . $token, [
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'proof_document_url' => $request->proof_document_url,
            'role' => 'space_owner',
            'status' => 'pending',
            'otp' => $otp,
        ], now()->addMinutes(10));


        $isSent = BrevoMailService::sendHtmlMail(
            $request->email,
            $request->name ?? 'مستخدم',
            'رمز التحقق الخاص بك',
            'emails.otp',
            ['otp' => $otp, 'userName' => $request->name ?? 'المستخدم']
        );

        if (!$isSent) {
            return response()->json([
                'message' => 'فشل إرسال البريد الإلكتروني',
            ], 500);
        } else {
            return response()->json([
                'meassage' => 'تم ارسال الكود, يرجى تفقد الايميل الخاص بك',
                'registration_token' => $token
            ], 200);
        }

    }

    public function registerCustomerAccount(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|numeric|unique:users,phone',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $otp = rand(100000, 999999);
        $token = Str::uuid()->toString();
        Cache::put('pending_registration_' . $token, [
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'customer',
            'status' => 'active',
            'otp' => $otp,
        ], now()->addMinutes(10));

        $isSent = BrevoMailService::sendHtmlMail(
            $request->email,
            $request->name ?? 'مستخدم',
            'رمز التحقق الخاص بك',
            'emails.otp',
            ['otp' => $otp, 'userName' => $request->name ?? 'المستخدم']
        );


        if (!$isSent) {
            return response()->json([
                'message' => 'فشل إرسال البريد الإلكتروني',
            ], 500);
        } else {
            return response()->json([
                'message' => 'تم ارسال الكود, يرجى تفقد الايميل الخاص بك',
                'registration_token' => $token
            ], 200);
        }

    }
    public function resendOtp(Request $request)
    {
        $request->validate([
            'registration_token' => 'required|string'
        ]);

        $rateLimitKey = 'resend-otp:' . $request->registration_token;
        $dataKey = 'pending_registration_' . $request->registration_token;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            $minutes = ceil($seconds / 60);

            return response()->json([
                'message' => "لقد تجاوزت الحد المسموح. يرجى المحاولة بعد {$minutes} دقيقة",
            ], 429);
        }

        RateLimiter::hit($rateLimitKey, 600);

        $data = Cache::get($dataKey);
        if (!$data) {
            return response()->json([
                'message' => 'انتهت صلاحية الجلسة، يرجى التسجيل من جديد'
            ], 400);
        }
        if (!is_array($data)) {
            return response()->json([
                'message' => 'حدث خطأ، يرجى التسجيل من جديد'
            ], 400);
        }
        $newOtp = rand(100000, 999999);
        $data['otp'] = $newOtp;

        Cache::put($dataKey, $data, now()->addMinutes(10));

        $isSent = BrevoMailService::sendHtmlMail(
            $data['email'],
            $data['name'] ?? 'مستخدم',
            'رمز التحقق الخاص بك',
            'emails.otp',
            ['otp' => $newOtp, 'userName' => $data['name'] ?? 'المستخدم']
        );

        if (!$isSent) {
            return response()->json([
                'message' => 'فشل إرسال البريد الإلكتروني',
            ], 500);
        }

        $remaining = RateLimiter::remaining($rateLimitKey, 5);

        return response()->json([
            'message' => 'تم ارسال الكود الجديد بنجاح, يرجى تفقد الايميل الخاص بك',
            'remaining_attempts' => $remaining,
        ], 200);
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


    public function verifyOtp(Request $request)
    {
        $request->validate([
            'registration_token' => 'required|string',
            'code' => 'required|string|size:6'
        ]);

        $pendingData = Cache::get('pending_registration_' . $request->registration_token);

        if (!$pendingData) {
            return response()->json([
                'message' => 'انتهت صلاحية الجلسة، يرجى إعادة محاولة التسجيل من جديد'
            ], 400);
        }

        if ($pendingData['otp'] != $request->code) {
            return response()->json([
                'message' => 'رمز التحقق غير صحيح'
            ], 400);
        }

        if (isset($pendingData['proof_document_url'])) {
            $user = User::create([
                'full_name' => $pendingData['name'],
                'phone' => $pendingData['phone'],
                'email' => $pendingData['email'],
                'email_verified_at' => now(),
                'password' => $pendingData['password'],
                'proof_document_url' => $pendingData['proof_document_url'],
                'role' => $pendingData['role'],
                'status' => $pendingData['status'],
                'created_at' => now(),
                'updated_at' => now()
            ]);
        } else {
            $user = User::create([
                'full_name' => $pendingData['name'],
                'phone' => $pendingData['phone'],
                'email' => $pendingData['email'],
                'email_verified_at' => now(),
                'password' => $pendingData['password'],
                'role' => $pendingData['role'],
                'status' => $pendingData['status'],
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }


        Cache::delete('pending_registration_' . $request->registration_token);
        Cache::delete('resend-otp:' . $request->registration_token);

        return response()->json([
            'user' => $user,
            'message' => 'تم انشاء الحساب بنجاح'
        ], 200);
    }
}
