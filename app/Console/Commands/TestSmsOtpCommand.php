<?php

namespace App\Console\Commands;

use App\Services\MessageCentralOtpService;
use Illuminate\Console\Command;

class TestSmsOtpCommand extends Command
{
    protected $signature = 'otp:test-sms
        {country_code : e.g. +961 or 961}
        {phone : mobile without country code, e.g. 78657964}
        {--code= : optional OTP code to validate after send}';

    protected $description = 'Test Message Central SMS OTP send (and optional validate)';

    public function handle(MessageCentralOtpService $messageCentral): int
    {
        $ccDigits = $messageCentral->countryCodeDigits((string) $this->argument('country_code'));
        $phone = $messageCentral->mobileNumberDigits($ccDigits, (string) $this->argument('phone'));

        $this->line('Message Central SMS test');
        $this->line('  countryCode: '.$ccDigits);
        $this->line('  mobileNumber: '.$phone.' (national digits only)');
        $this->line('  sms_configured: '.($messageCentral->isSmsConfigured() ? 'yes' : 'no'));
        $this->newLine();

        if (! $messageCentral->isSmsConfigured()) {
            $this->error('SMS not configured. Set MESSAGE_CENTRAL_* and OTP_MESSAGE_CENTRAL_SMS_ENABLED=true in .env');

            return self::FAILURE;
        }

        $result = $messageCentral->sendOtp($ccDigits, $phone, 'SMS');

        if ($result['ok'] ?? false) {
            $verificationId = (string) ($result['verification_id'] ?? '?');
            $this->info('SUCCESS — verification_id: '.$verificationId);
            if (isset($result['body'])) {
                $this->line(json_encode($result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            $code = (string) $this->option('code');
            if ($code !== '') {
                $this->newLine();
                $this->line('Validating code...');
                $validate = $messageCentral->validateOtp($verificationId, $code, 'SMS', [
                    'country_code_digits' => $ccDigits,
                    'mobile_number' => $phone,
                    'auth_token' => isset($result['auth_token']) ? (string) $result['auth_token'] : '',
                ]);
                if ($validate['ok'] ?? false) {
                    $this->info('VALIDATE SUCCESS');
                } else {
                    $this->error('VALIDATE FAILED — '.($validate['error'] ?? 'unknown'));
                    $this->line(json_encode($validate, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                    return self::FAILURE;
                }
            }

            return self::SUCCESS;
        }

        $this->error('FAILED — error: '.($result['error'] ?? 'unknown'));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::FAILURE;
    }
}
