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

        $systemContext = Storage::disk('local')->get('masahati_context.txt');

        $apiKey = config('services.gemini.key');

        $response = Http::post(
            "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key={$apiKey}",
            [
                'system_instruction' => [
                    'parts' => [['text' => $systemContext]]
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
