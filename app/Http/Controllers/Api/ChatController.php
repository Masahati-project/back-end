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
        $request->validate([
            'message' => 'required|string',
        ]);

        // 1. تحديد مسار الملف داخل مجلد resources
        $filePath = resource_path('prompt/masahati_context.txt');

        // 2. التحقق من وجود الملف
        if (!file_exists($filePath)) {
            return response()->json(['error' => 'الملف غير موجود في resources/prompt/masahati_context.txt'], 500);
        }

        // 3. قراءة محتوى الملف
        $systemContext = file_get_contents($filePath);

        if (empty(trim($systemContext))) {
            return response()->json(['error' => 'ملف التعليمات فارغ تماماً'], 500);
        }

        $apiKey = config('services.gemini.key');

        // 4. إرسال الطلب مع التأكد من اسم النموذج الصحيح
        $response = Http::post(
            "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key={$apiKey}",
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
                'error' => 'فشل الاتصال بـ Gemini',
                'details' => $response->json(),
            ], 500);
        }

        $data = $response->json();
        $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'ما في رد';

        return response()->json([
            'reply' => $reply,
        ]);
    }
}
