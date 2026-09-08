<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Http\Request;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

class AiAssistantController extends Controller
{
    /**
     * بدء محادثة جديدة
     */
    public function start(Request $request)
    {
        $conversation = AiConversation::create([
            'user_id' => auth()->id(),
            'title' => $request->input('title', 'محادثة جديدة'),
        ]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'title' => $conversation->title,
        ]);
    }


    /**
     * إرسال رسالة إلى الذكاء الاصطناعي
     */
    public function ask(Request $request)
    {
        // التحقق من البيانات القادمة من Frontend
        $request->validate([
            'message' => 'required|string|max:2000',
            'conversation_id' => 'nullable|exists:ai_conversations,id',
        ]);


        /*
        |--------------------------------------------------------------------------
        | الحصول على المحادثة
        |--------------------------------------------------------------------------
        */

        if ($request->conversation_id) {

            $conversation = AiConversation::findOrFail(
                $request->conversation_id
            );

        } else {

            $conversation = AiConversation::create([
                'user_id' => auth()->id(),
                'title' => 'محادثة جديدة',
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | حفظ رسالة المستخدم
        |--------------------------------------------------------------------------
        */

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $request->message,
        ]);


        /*
        |--------------------------------------------------------------------------
        | جلب تاريخ المحادثة
        |--------------------------------------------------------------------------
        */

        $history = $conversation->messages()
            ->orderBy('id')
            ->get();


        /*
        |--------------------------------------------------------------------------
        | تحويل المحادثة إلى نص
        |--------------------------------------------------------------------------
        */

        $conversationText = $history
            ->map(function ($message) {

                if ($message->role === 'user') {
                    return 'المستخدم: ' . $message->content;
                }

                return 'المساعد: ' . $message->content;
            })
            ->implode("\n");


        /*
        |--------------------------------------------------------------------------
        | الاتصال بـ OpenAI
        |--------------------------------------------------------------------------
        */

        try {

            $response = Prism::text()
                ->using(
                    Provider::OpenAI,
                    'gpt-4o-mini'
                )
                ->withSystemPrompt(
                    'أنت مساعد ذكي داخل تطبيق "مساحتي".

                    مهمتك مساعدة المستخدم والإجابة عن أسئلته
                    بطريقة واضحة ومختصرة ومفيدة.

                    تحدث باللغة العربية عندما يكتب المستخدم بالعربية.

                    لا تذكر للمستخدم تفاصيل تقنية عن API
                    أو المفاتيح أو طريقة عمل النظام.

                    إذا كان السؤال غير واضح، اطلب توضيحًا.'
                )
                ->withPrompt($conversationText)
                ->asText();


            /*
            |--------------------------------------------------------------------------
            | الحصول على رد الذكاء الاصطناعي
            |--------------------------------------------------------------------------
            */

            $reply = $response->text;


        } catch (\Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | في حالة حدوث خطأ
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => false,
                'error' => 'حدث خطأ أثناء الاتصال بالذكاء الاصطناعي',
                'provider' => 'openai',
                'details' => $e->getMessage(),
            ], 500);
        }


        /*
        |--------------------------------------------------------------------------
        | حفظ رد الذكاء الاصطناعي
        |--------------------------------------------------------------------------
        */

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $reply,
        ]);


        /*
        |--------------------------------------------------------------------------
        | إرسال النتيجة إلى Frontend
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'success' => true,
            'conversation_id' => $conversation->id,
            'provider' => 'openai',
            'reply' => $reply,
        ]);
    }


    /**
     * عرض تاريخ محادثة معينة
     */
    public function history($conversationId)
    {
        $conversation = AiConversation::with('messages')
            ->findOrFail($conversationId);

        return response()->json([
            'success' => true,
            'conversation' => $conversation,
        ]);
    }


    /**
     * عرض جميع محادثات المستخدم الحالي
     */
    public function index()
    {
        $conversations = AiConversation::where(
            'user_id',
            auth()->id()
        )
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'conversations' => $conversations,
        ]);
    }


    /**
     * حذف محادثة
     */
    public function destroy($conversationId)
    {
        $conversation = AiConversation::findOrFail(
            $conversationId
        );

        $conversation->messages()->delete();

        $conversation->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف المحادثة بنجاح',
        ]);
    }
}