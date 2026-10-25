<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BroadcastMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $title, public string $body, public ?string $link = null) {}

    public function build()
    {
        return $this->subject($this->title)
            ->view('email.broadcast')
            ->with([
                'title' => $this->title,
                'body' => $this->body,
                'link' => $this->link,
            ]);
    }
}
