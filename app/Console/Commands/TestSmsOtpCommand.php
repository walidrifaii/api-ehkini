<?php

namespace App\Console\Commands;

use App\Services\MessageCentralOtpService;
use Illuminate\Console\Command;

class TestSmsOtpCommand extends Command
{
    protected $signature = 'otp:test-sms
        {country_code : e.g. +961 or 961}
        {phone : mobile without country code, e.g. 78657964}';

    protected $description = 'Test Message Central SMS OTP send and print full API response';

    public function handle(MessageCentralOtpService $messageCentral): int
    {
        $ccDigits = $messageCentral->countryCodeDigits((string) $this->argument('country_code'));
        $phone = ltrim(preg_replace('/\D+/', '', (string) $this->argument('phone')) ?? '', '0');

        $this->line('Message Central SMS test');
        $this->line('  countryCode: '.$ccDigits);
        $this->line('  mobileNumber: '.$phone);
        $this->line('  sms_configured: '.($messageCentral->isSmsConfigured() ? 'yes' : 'no'));
        $this->newLine();

        if (! $messageCentral->isSmsConfigured()) {
            $this->error('SMS not configured. Set MESSAGE_CENTRAL_* and OTP_MESSAGE_CENTRAL_SMS_ENABLED=true in .env');

            return self::FAILURE;
        }

        $result = $messageCentral->sendOtp($ccDigits, $phone, 'SMS');

        if ($result['ok'] ?? false) {
            $this->info('SUCCESS — verification_id: '.($result['verification_id'] ?? '?'));
            if (isset($result['body'])) {
                $this->line(json_encode($result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            return self::SUCCESS;
        }

        $this->error('FAILED — error: '.($result['error'] ?? 'unknown'));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::FAILURE;
    }
}
