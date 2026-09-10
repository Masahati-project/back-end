<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BrevoMailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class OtpController extends Controller
{
    public static function sendOtp($email, $name, $otp)
    {
        return BrevoMailService::sendHtmlMail(
            $email,
            $name,
            'رمز التحقق الخاص بك - مساحاتي',
            'email.otp',
            ['otp' => $otp, 'userName' => $name]
        );
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
        try {
            if ($pendingData['role']=='space_owner') {
                $user = User::create([
                    'full_name' => $pendingData['name'],
                    'phone' => $pendingData['phone'],
                    'email' => $pendingData['email'],
                    'verified_at' => now(),
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
                    'verified_at' => now(),
                    'password' => $pendingData['password'],
                    'role' => $pendingData['role'],
                    'status' => $pendingData['status'],
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
        } catch (\Throwable $th) {
            return $th->getMessage();
        }



        Cache::delete('pending_registration_' . $request->registration_token);
        Cache::delete('resend-otp:' . $request->registration_token);

        return response()->json([
            'user' => $user,
            'message' => 'تم انشاء الحساب بنجاح'
        ], 200);
    }
}
