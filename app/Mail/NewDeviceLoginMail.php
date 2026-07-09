<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewDeviceLoginMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $firstName = '',
    ) {}

    public function build()
    {
        $greeting = $this->firstName !== '' ? 'Hi '.e($this->firstName).',' : 'Hi,';

        return $this
            ->subject('New login to your Ehkini account')
            ->html("
                <div style='font-family: Arial, sans-serif; line-height: 1.6;'>
                    <h2>New login detected</h2>
                    <p>{$greeting}</p>
                    <p>Your Ehkini account was just signed in from a new device.</p>
                    <p>If this was you, no action is needed. If you don't recognize this activity, please change your password immediately.</p>
                </div>
            ");
    }
}
