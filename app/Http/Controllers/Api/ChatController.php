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
        $request->validate(['message' => 'required|string']);

        $systemPrompt = <<<EOT
إنت المساعد الذكي لمنصة "مساحاتي" (Masahati) — منصة ويب لاكتشاف وحجز مساحات العمل المشتركة في قطاع غزة.

المنصة بتساعد المستخدمين يلاقوا مساحة عمل مناسبة عن طريق:
- تصفح المساحات على خريطة تفاعلية
- فلترة ومقارنة حسب السعر، الموقع، السعة، والخدمات
- حجز مباشر لمقعد أو مساحة كاملة
- دفع إلكتروني آمن
- تقييمات من مستخدمين حقيقيين
- تم تصميم المنصة بواسطة : مهند جراد ‘ أمير عياد ‘ عبد الحمن العطار ‘ سوزان فرج ‘ براءة الحسني

كون مختصر ومباشر.
EOT;

        $response = Http::withToken(config('services.groq.key'))
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => 'openai/gpt-oss-120b',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $request->input('message')],
                ],
            ]);

        if ($response->failed()) {
            return response()->json([
                'error' => 'فشل الاتصال',
                'status' => $response->status(),
                'details' => $response->json(),
            ], 500);
        }

        $reply = $response->json()['choices'][0]['message']['content'] ?? 'ما في رد';

        return response()->json(['reply' => $reply]);
    }
}
