<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;

class BrevoMailService
{
    /**
     * إرسال بريد إلكتروني باستخدام Brevo API وقالب Blade
     */
    public static function sendHtmlMail(string $toEmail, string $toName, string $subject, string $view, array $data = []): bool
    {
        // رندر القالب لتحويله إلى HTML
        $htmlContent = View::make($view, $data)->render();

        $response = Http::withHeaders([
            'accept' => 'application/json',
            'api-key' => config('services.brevo.key', env('BREVO_API_KEY')),
            'content-type' => 'application/json',
        ])->post('https://api.brevo.com/v3/smtp/email', [
            'sender' => [
                'name' => config('mail.from.name', 'Masahati'),
                'email' => config('mail.from.address', env('MAIL_FROM_ADDRESS', 'mohannadjarad6@gmail.com')),
            ],
            'to' => [
                [
                    'email' => $toEmail,
                    'name' => $toName,
                ]
            ],
            'subject' => $subject,
            'htmlContent' => $htmlContent,
        ]);

        return $response->successful();
    }
}
