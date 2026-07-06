<?php

namespace App\Console\Commands;

use App\Services\MessageCentralOtpService;
use App\Services\OtpDeliveryService;
use App\Services\UnoSmsOtpService;
use Illuminate\Console\Command;

class TestSmsOtpCommand extends Command
{
    protected $signature = 'otp:test-sms
        {country_code : e.g. +961 or 961}
        {phone : national number or full intl, e.g. 70657961 or 96170657961}
        {--code= : optional OTP code to validate after send (UnoSMS/Lebanon only)}';

    protected $description = 'Test SMS OTP send (Lebanon → UnoSMS, other countries → Message Central)';

    public function handle(
        OtpDeliveryService $otp,
        MessageCentralOtpService $messageCentral,
        UnoSmsOtpService $unoSms,
    ): int {
        $ccDigits = $messageCentral->countryCodeDigits((string) $this->argument('country_code'));
        $phone = $messageCentral->mobileNumberDigits($ccDigits, (string) $this->argument('phone'));
        $countryCode = str_starts_with((string) $this->argument('country_code'), '+')
            ? (string) $this->argument('country_code')
            : '+' . ltrim((string) $this->argument('country_code'), '+');

        $provider = $unoSms->isLebanon($ccDigits) ? 'UnoSMS (Lebanon)' : 'Message Central';

        $this->line('SMS OTP test');
        $this->line('  provider: ' . $provider);
        $this->line('  countryCode: ' . $ccDigits);
        $this->line('  mobileNumber: ' . $phone);
        $this->line('  uno_configured: ' . ($unoSms->isConfigured() ? 'yes' : 'no'));
        $this->line('  mc_configured: ' . ($messageCentral->isSmsConfigured() ? 'yes' : 'no'));
        $this->newLine();

        if (! $otp->isSmsAvailableForCountry($countryCode)) {
            $this->error($unoSms->isLebanon($ccDigits)
                ? 'Lebanon SMS not configured. Set UNOSMS_USER, UNOSMS_PASS, OTP_UNOSMS_ENABLED=true in .env'
                : 'SMS not configured. Set MESSAGE_CENTRAL_* and OTP_MESSAGE_CENTRAL_SMS_ENABLED=true in .env');

            return self::FAILURE;
        }

        $result = $otp->sendOtp('test', $countryCode, $phone, 'sms');

        if ($result['ok'] ?? false) {
            $this->info('SUCCESS — channel: ' . ($result['channel'] ?? 'sms'));
            if (isset($result['otp_token'])) {
                $this->line('  otp_token: ' . substr((string) $result['otp_token'], 0, 24) . '...');
            }

            $code = (string) $this->option('code');
            if ($code !== '' && isset($result['otp_token'])) {
                $phoneE164 = $countryCode . $phone;
                $this->newLine();
                $this->line('Validating code...');
                $validate = $otp->verifyOtp((string) $result['otp_token'], 'test', $phoneE164, $code);
                if ($validate['ok'] ?? false) {
                    $this->info('VALIDATE SUCCESS');
                } else {
                    $this->error('VALIDATE FAILED — ' . ($validate['error'] ?? 'unknown'));

                    return self::FAILURE;
                }
            }

            return self::SUCCESS;
        }

        $this->error('FAILED — error: ' . ($result['error'] ?? $result['error_summary'] ?? 'unknown'));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::FAILURE;
    }
}
