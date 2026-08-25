<?php
namespace App\Notifications;

use Illuminate\Notifications\Notification;
use App\Notifications\Channels\BrevoChannel;

class SendOtpNotification extends Notification
{
    public $otp;

    public function __construct($otp)
    {
        $this->otp = $otp;
    }

    public function via($notifiable)
    {
        return [BrevoChannel::class];
    }

    public function toBrevo($notifiable)
    {
        return [
            'subject' => 'رمز تأكيد الحساب',
            'html' => view('emails.verification-code', ['code' => $this->otp])->render(),
        ];
    }
}