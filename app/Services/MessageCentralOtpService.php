<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class MessageCentralOtpService
{
    public function isSmsConfigured(): bool
    {
        $cfg = config('otp.message_central');

        return ($cfg['enabled'] ?? false)
            && ($cfg['sms_enabled'] ?? false)
            && $this->hasCredentials();
    }

    public function isWhatsAppConfigured(): bool
    {
        $cfg = config('otp.message_central');

        return ($cfg['enabled'] ?? false)
            && ($cfg['whatsapp_enabled'] ?? false)
            && $this->hasCredentials();
    }

    public function sendOtp(string $countryCodeDigits, string $mobileNumber, string $flowType = 'SMS'): array
    {
        if (! $this->hasCredentials()) {
            return ['ok' => false, 'error' => 'message_central_not_configured'];
        }

        $cfg = config('otp.message_central');
        $customerId = $this->normalizeCustomerId((string) $cfg['customer_id']);

        $response = Http::withHeaders([
            'authToken' => (string) $cfg['auth_token'],
            'accept' => '*/*',
        ])->timeout(20)->post($cfg['base_url'] . '/verification/v3/send', [
            'countryCode' => $countryCodeDigits,
            'customerId' => $customerId,
            'mobileNumber' => $mobileNumber,
            'flowType' => strtoupper($flowType),
            'otpLength' => (int) ($cfg['otp_length'] ?? 6),
        ]);

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => 'message_central_send_failed',
                'http' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        }

        $json = $response->json() ?? [];
        $verificationId = data_get($json, 'data.verificationId');

        if (! $verificationId) {
            return [
                'ok' => false,
                'error' => 'message_central_no_verification_id',
                'body' => $json,
            ];
        }

        return [
            'ok' => true,
            'verification_id' => (string) $verificationId,
            'flow_type' => strtoupper($flowType),
            'body' => $json,
        ];
    }

    public function validateOtp(string $verificationId, string $code, string $flowType = 'SMS'): array
    {
        if (! $this->hasCredentials()) {
            return ['ok' => false, 'error' => 'message_central_not_configured'];
        }

        $cfg = config('otp.message_central');

        $response = Http::withHeaders([
            'authToken' => (string) $cfg['auth_token'],
            'accept' => '*/*',
        ])->timeout(20)->get($cfg['base_url'] . '/verification/v3/validateOtp', [
            'verificationId' => $verificationId,
            'code' => $code,
            'flowType' => strtoupper($flowType),
        ]);

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => 'message_central_validate_failed',
                'http' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        }

        $json = $response->json() ?? [];
        $status = data_get($json, 'data.verificationStatus');

        if (strtoupper((string) $status) !== 'VERIFICATION_COMPLETED') {
            return [
                'ok' => false,
                'error' => 'invalid_code',
                'body' => $json,
            ];
        }

        return ['ok' => true, 'body' => $json];
    }

    public function countryCodeDigits(string $countryCode): string
    {
        return ltrim(preg_replace('/\D+/', '', $countryCode) ?? '', '0');
    }

    private function hasCredentials(): bool
    {
        $cfg = config('otp.message_central');

        return ($cfg['customer_id'] ?? '') !== '' && ($cfg['auth_token'] ?? '') !== '';
    }

    private function normalizeCustomerId(string $customerId): string
    {
        $customerId = trim($customerId);
        if ($customerId === '') {
            return $customerId;
        }

        return str_starts_with($customerId, 'C-') ? $customerId : ('C-' . $customerId);
    }
}
