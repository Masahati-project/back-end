<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class PhoneVerificationController extends Controller
{
    /**
     * إرسال رمز OTP إلى رقم الهاتف
     */
    public function sendCode(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|max:20',
        ]);

        // تنظيف رقم الهاتف
        $phone = preg_replace('/[^0-9+]/', '', $request->phone);

        // إنشاء كود من 6 أرقام
        $code = (string) random_int(100000, 999999);

        // حفظ الكود لمدة 5 دقائق
        Cache::put(
            'phone_otp_' . $phone,
            $code,
            now()->addMinutes(5)
        );

        try {

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('SMS_TO_API_KEY'),
                'Content-Type' => 'application/json',
            ])->post(
                'https://api.sms.to/sms/send',
                [
                    'message' => "رمز التحقق الخاص بك هو: {$code}. الرمز صالح لمدة 5 دقائق.",

                    'to' => $phone,

                    'sender_id' => env(
                        'SMS_TO_SENDER_ID',
                        'SMSto'
                    ),
                ]
            );

            if (!$response->successful()) {

                // حذف الكود إذا فشل الإرسال
                Cache::forget('phone_otp_' . $phone);

                return response()->json([
                    'success' => false,
                    'message' => 'فشل إرسال رمز التحقق',
                    'details' => $response->json(),
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم إرسال رمز التحقق إلى هاتفك',
            ]);

        } catch (\Throwable $e) {

            Cache::forget('phone_otp_' . $phone);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء إرسال رمز التحقق',
                'details' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * التحقق من رمز OTP
     */
    public function verifyCode(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|max:20',
            'code' => 'required|string|size:6',
        ]);

        $phone = preg_replace('/[^0-9+]/', '', $request->phone);

        $code = $request->code;

        // جلب الكود المحفوظ
        $savedCode = Cache::get(
            'phone_otp_' . $phone
        );

        // إذا انتهت مدة الكود
        if (!$savedCode) {

            return response()->json([
                'success' => false,
                'message' => 'رمز التحقق منتهي أو غير موجود',
            ], 422);
        }

        // التحقق من الكود
        if ((string) $savedCode !== (string) $code) {

            return response()->json([
                'success' => false,
                'message' => 'رمز التحقق غير صحيح',
            ], 422);
        }

        // حذف الكود بعد نجاح التحقق
        Cache::forget(
            'phone_otp_' . $phone
        );

        return response()->json([
            'success' => true,
            'message' => 'تم التحقق من رقم الهاتف بنجاح',
        ]);
    }
}