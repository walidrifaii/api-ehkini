<?php

namespace App\Console\Commands;

use App\Services\MessageCentralOtpService;
use App\Services\UnoSmsOtpService;
use Illuminate\Console\Command;

class SendTestSmsCommand extends Command
{
    protected $signature = 'sms:send
        {country_code : e.g. +961}
        {phone : national number, e.g. 71887115}
        {message? : optional SMS text}';

    protected $description = 'Send a plain SMS via UnoSMS (Lebanon, no OTP)';

    public function handle(UnoSmsOtpService $unoSms, MessageCentralOtpService $messageCentral): int
    {
        $countryCode = (string) $this->argument('country_code');
        $phone = (string) $this->argument('phone');
        $message = (string) ($this->argument('message') ?? 'Test message from Ehkini.');

        if (! $unoSms->isConfigured()) {
            $this->error('UnoSMS is not configured. Set UNOSMS_USER, UNOSMS_PASS, OTP_UNOSMS_ENABLED=true');

            return self::FAILURE;
        }

        $ccDigits = $messageCentral->countryCodeDigits($countryCode);
        $mobileDigits = $messageCentral->mobileNumberDigits($ccDigits, $phone);
        $cc = str_starts_with($countryCode, '+') ? $countryCode : '+' . ltrim($countryCode, '+');
        $phoneE164 = $cc . $mobileDigits;

        $this->line('Sending plain SMS via UnoSMS');
        $this->line('  to E164: ' . $phoneE164);
        $this->line('  message: ' . $message);

        $result = $unoSms->sendMessage($phoneE164, $message);

        if ($result['ok'] ?? false) {
            $this->info('SUCCESS');
            $this->line('  gateway to: ' . ($result['to'] ?? ''));
            $this->line('  body: ' . ($result['body'] ?? ''));

            return self::SUCCESS;
        }

        $this->error('FAILED — ' . ($result['error'] ?? 'unknown'));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::FAILURE;
    }
}
