<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $otp;

    public function __construct(string $otp)
    {
        $this->otp = $otp;
    }

    public function build()
    {
        return $this->subject('Your Verification Code')
            ->html("
                <div style='font-family: Arial, sans-serif; line-height: 1.6;'>
                    <h2>Email Verification</h2>
                    <p>Your verification code is:</p>
                    <h1 style='letter-spacing: 4px;'>{$this->otp}</h1>
                    <p>If you didn’t request this, ignore this email.</p>
                </div>
            ");
    }
}
