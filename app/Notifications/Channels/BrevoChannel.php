<?php
namespace App\Notifications\Channels;

use App\Notifications\SendOtpNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BrevoChannel
{
    public function send($notifiable, SendOtpNotification $notification)
    {
        $data = $notification->toBrevo($notifiable);

        $response = Http::withHeaders([
            'accept' => 'application/json',
            'api-key' => env('BREVO_API_KEY'),
            'content-type' => 'application/json',
        ])->post('https://api.brevo.com/v3/smtp/email', [
            'sender' => [
                'name' => config('app.name'),
                'email' => env('BREVO_SENDER_EMAIL'),
            ],
            'to' => [
                ['email' => $notifiable->routeNotificationFor('mail') ?? $notifiable->email],
            ],
            'subject' => $data['subject'],
            'htmlContent' => $data['html'],
        ]);

        if ($response->failed()) {
            Log::error('Brevo mail failed: ' . $response->body());
        }
    }
}