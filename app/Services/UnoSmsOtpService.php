<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lebanon SMS OTP via UnoSMS (sms.unosms.net).
 * Generates OTP locally and verifies via encrypted otp_token (same as WhatsApp node).
 */
class UnoSmsOtpService
{
    public function isConfigured(): bool
    {
        $cfg = config('otp.unosms');

        return ($cfg['enabled'] ?? false)
            && ($cfg['user'] ?? '') !== ''
            && ($cfg['pass'] ?? '') !== '';
    }

    public function isLebanon(string $countryCodeDigits): bool
    {
        $cc = ltrim(preg_replace('/\D+/', '', $countryCodeDigits) ?? '', '0');

        foreach ($this->countryCodes() as $code) {
            if ($cc === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function countryCodes(): array
    {
        $codes = config('otp.unosms.country_codes', ['961']);

        return array_values(array_filter(array_map(
            static fn ($code) => ltrim(preg_replace('/\D+/', '', (string) $code) ?? '', '0'),
            is_array($codes) ? $codes : [$codes],
        )));
    }

    public function phoneDigits(string $phoneE164): string
    {
        return preg_replace('/\D+/', '', $phoneE164) ?? '';
    }

    /**
     * @return array{ok: bool, error?: string, channel?: string, otp_token?: string, expires_in?: int, http?: int, body?: string, detail?: string}
     */
    public function sendOtp(string $phoneE164, string $code, string $purpose = 'register'): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'unosms_not_configured'];
        }

        $cleanPhone = $this->phoneDigits($phoneE164);
        if ($cleanPhone === '') {
            return ['ok' => false, 'error' => 'invalid_phone'];
        }

        $cfg = config('otp.unosms');
        $message = 'Your verification code is ' . $code . '. Valid for 5 minutes.';

        $params = [
            'user' => (string) $cfg['user'],
            'pass' => (string) $cfg['pass'],
            'to' => $cleanPhone,
            'from' => (string) ($cfg['from'] ?? 'Ehkini'),
            'msg' => $message,
        ];

        $baseUrl = rtrim((string) ($cfg['url'] ?? 'https://sms.unosms.net/api.php'), '?');
        $url = $baseUrl . '?' . http_build_query($params);

        try {
            $response = Http::withUserAgent('SmsService/1.0')
                ->timeout((int) ($cfg['timeout'] ?? 15))
                ->withOptions(['verify' => true])
                ->get($url);
        } catch (\Throwable $e) {
            Log::error('unosms.request_failed', [
                'error' => $e->getMessage(),
                'to_last4' => substr($cleanPhone, -4),
            ]);

            return [
                'ok' => false,
                'error' => 'SMS service is not reachable',
                'detail' => $e->getMessage(),
            ];
        }

        $body = trim((string) $response->body());

        if (! $response->successful() || $this->looksLikeFailure($body)) {
            Log::warning('unosms.send_failed', [
                'http' => $response->status(),
                'body' => $body,
                'to_last4' => substr($cleanPhone, -4),
            ]);

            return [
                'ok' => false,
                'error' => $body !== '' ? $body : 'unosms_send_failed',
                'http' => $response->status(),
                'body' => $body,
            ];
        }

        return [
            'ok' => true,
            'body' => $body,
        ];
    }

    private function looksLikeFailure(string $body): bool
    {
        if ($body === '') {
            return false;
        }

        $lower = strtolower($body);

        foreach (['error', 'fail', 'invalid', 'denied', 'rejected', 'wrong password', 'no credit'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
