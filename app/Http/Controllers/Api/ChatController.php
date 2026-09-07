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

        $systemInstruction = <<<TEXT
أنت المساعد الذكي المخصص لمنصة "مساحاتي" (Masahati)، وهي منصة لاكتشاف وحجز مساحات العمل المشتركة في قطاع غزة.

تعليمات الإجابة:
1. أجب دائمًا بناءً على الأسئلة الشائعة والمعلومات الواردة أدناه.
2. قدم إجابات دقيقة ومباشرة بأسلوب مهني.
3. إذا سئلت عن موضوع خارج نطاق المنصة، وضح بأدب أنك متخصص فقط في منصة "مساحاتي".

--- أسئلة وأجوبة منصة مساحاتي (FAQ) ---
- ما هي منصة مساحاتي؟ هي منصة ويب لاكتشاف وحجز مساحات العمل في غزة لتوفير الكهرباء والإنترنت، تتيح البحث والتصفح عبر خريطة تفاعلية وحجز المقاعد أو المساحات الكاملة.
- المشكلة التي تحلها: تشتت البيانات، صعوبة العثور على إنترنت وكهرباء، الحجز اليدوي المرهق، وعدم وجود تقييمات موثوقة.
- الفئات المستهدفة: المستقلين، الطلاب، الشركات الناشئة، وأصحاب المساحات.
- التقنيات المستخدمة: React للواجهة الأمامية، Laravel + MySQL للعمليات الخلفية، Mapbox/Google Maps للخرائط، وبوابات دفع إلكترونية.
- خيارات الحجز: حجز مقعد فردي أو حجز المساحة بالكامل، مع إمكانية الإلغاء ضمن الإطار الزمني المسموح به.
- أصحاب المساحات: يلزم إثبات الملكية لتفعيل الحساب، وتوفر لهم لوحة تحكم لتحديد الأسعار (ساعي/يومي/شهري) وحظر الأوقات غير المتاحة.
- فريق العمل: مهند جراد، عبد الرحمن العطار، أمير عياد، سوزان فرج، وبراء الحسني.
TEXT;

        $apiKey = config('services.gemini.key');

        $response = Http::post(
            "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key={$apiKey}",
            [
                'system_instruction' => [
                    'parts' => [['text' => $systemInstruction]]
                ],

                'contents' => [
                    [
                        'parts' => [
                            ['text' => $request->input('message')]
                        ]
                    ]
                ],

                'generationConfig' => [
                    'maxOutputTokens' => 250, // تحديد طول الإجابة لسرعة التوليد
                    'temperature' => 0.2     // تقليل العشوائية لرد أسرع وأكثر دقة
                ]
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
