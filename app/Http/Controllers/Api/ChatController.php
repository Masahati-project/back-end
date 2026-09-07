<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ChatController extends Controller
{
    public function sendMessage(Request $request)
    {
        try {
            $request->validate([
                'message' => 'required|string',
            ]);

            // 1. تحديد المسار بدقة
            $filePath = resource_path('prompt/masahati_context.txt');

            if (!file_exists($filePath)) {
                return response()->json([
                    'error' => 'الملف غير موجود في المسار: ' . $filePath
                ], 404);
            }

            $systemContext = file_get_contents($filePath);

            $apiKey = config('services.gemini.key');

            if (empty($apiKey)) {
                return response()->json([
                    'error' => 'مفتاح Gemini API غير معرف في config/services.php أو .env'
                ], 500);
            }

            // 2. إرسال الطلب لـ Gemini
            $response = Http::timeout(30)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}",
                [
                    'system_instruction' => [
                        'parts' => [
                            ['text' => $systemContext]
                        ]
                    ],
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => $request->input('message')]
                            ]
                        ]
                    ],
                ]
            );

            if ($response->failed()) {
                return response()->json([
                    'error' => 'فشل الاتصال بـ Gemini API',
                    'details' => $response->json(),
                ], 400);
            }

            $data = $response->json();
            $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'لم يتم استلام رد.';

            return response()->json([
                'reply' => $reply,
            ]);
        } catch (\Exception $e) {
            // إرجاع تفاصيل الخطأ بدلاً من 500 مبهم
            return response()->json([
                'error' => 'حدث خطأ داخلي في السيرفر',
                'message' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }
}
