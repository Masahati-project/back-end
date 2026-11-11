<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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
رد حسب اللغة وكون مختصر ومباشر ,التطبيق فقط لقطاع غزة, السعر بالشيكل
EOT;

        $ownerContext = $request->input('owner_context');
        if (!empty($ownerContext) && is_array($ownerContext)) {
            $contextString = $this->formatOwnerContext($ownerContext);
            $systemPrompt .= "\n\nمعلومات صاحب المساحة:\n" . $contextString;
        }

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
                'message' => 'فشل الاتصال بالمساعد الذكي',
            ], 500);
        }

        $reply = $response->json()['choices'][0]['message']['content'] ?? 'لا توجد إجابة';

        return response()->json(['reply' => $reply]);
    }

    private function formatOwnerContext($context)
    {
        $parts = [];

        if (isset($context['spacesCount'])) {
            $parts[] = "عدد المساحات: " . $context['spacesCount'];
        }
        if (isset($context['activeSpacesCount'])) {
            $parts[] = "المساحات النشطة: " . $context['activeSpacesCount'];
        }
        if (isset($context['confirmedBookings'])) {
            $parts[] = "الحجوزات المؤكدة: " . $context['confirmedBookings'];
        }
        if (isset($context['bookingsThisWeek'])) {
            $parts[] = "الحجوزات هذا الأسبوع: " . $context['bookingsThisWeek'];
        }
        if (isset($context['totalRevenue'])) {
            $parts[] = "الإيرادات: " . $context['totalRevenue'] . " ش.ج";
        }
        if (isset($context['adsCount'])) {
            $parts[] = "الإعلانات: " . $context['adsCount'];
        }

        return implode("\n", $parts);
    }
}
