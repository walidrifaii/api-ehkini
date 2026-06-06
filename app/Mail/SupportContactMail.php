<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SupportContactMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $contactEmail,
        public string $messageText,
        public ?string $subjectLine = null,
        public ?string $userName = null,
        public ?int $userId = null,
    ) {
    }

    public function build()
    {
        $subject = $this->subjectLine ?: 'New support contact from '.$this->contactEmail;
        $userInfo = $this->userId
            ? "<p><strong>User ID:</strong> {$this->userId}</p>"
            : '';
        $nameInfo = $this->userName
            ? "<p><strong>Name:</strong> {$this->userName}</p>"
            : '';

        return $this
            ->replyTo($this->contactEmail)
            ->subject($subject)
            ->html("
                <div style='font-family: Arial, sans-serif; line-height: 1.6;'>
                    <h2>Support contact</h2>
                    <p><strong>Email:</strong> {$this->contactEmail}</p>
                    {$nameInfo}
                    {$userInfo}
                    <p><strong>Message:</strong></p>
                    <p style='white-space: pre-wrap;'>".e($this->messageText)."</p>
                </div>
            ");
    }
}
