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
        $parsed = $this->parseSendResponse($response->status(), $body);

        if (! ($parsed['ok'] ?? false)) {
            Log::warning('unosms.send_failed', [
                'http' => $response->status(),
                'body' => $body,
                'to_last4' => substr($cleanPhone, -4),
            ]);

            return [
                'ok' => false,
                'error' => (string) ($parsed['error'] ?? ($body !== '' ? $body : 'unosms_send_failed')),
                'http' => $response->status(),
                'body' => $body,
            ];
        }

        Log::info('unosms.send_ok', [
            'http' => $response->status(),
            'body' => $body,
            'to_last4' => substr($cleanPhone, -4),
        ]);

        return [
            'ok' => true,
            'body' => $body,
        ];
    }

    /**
     * UnoSMS gateways often return a numeric message id, "OK", or plain text on success.
     * The old substring check for "error" caused false failures while SMS was still delivered.
     *
     * @return array{ok: bool, error?: string}
     */
    private function parseSendResponse(int $httpStatus, string $body): array
    {
        $trimmed = trim($body);
        $lower = strtolower($trimmed);

        if ($trimmed === '' && $httpStatus >= 200 && $httpStatus < 300) {
            return ['ok' => true];
        }

        if (preg_match('/^\d+$/', $trimmed)) {
            return ['ok' => true];
        }

        if (in_array($lower, ['ok', 'success', 'sent', 'accepted', 'delivered', '1', 'true'], true)) {
            return ['ok' => true];
        }

        if (preg_match('/\b(sent|success|accepted|delivered|submitted)\b/i', $trimmed)) {
            return ['ok' => true];
        }

        $failurePatterns = [
            '/^(err(or)?|fail(ed)?|invalid|denied|rejected)\b/i',
            '/wrong\s+password/i',
            '/no\s+credit/i',
            '/insufficient(\s+credit|\s+balance)?/i',
            '/authentication\s+failed/i',
            '/unauthorized/i',
            '/bad\s+request/i',
        ];

        foreach ($failurePatterns as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return [
                    'ok' => false,
                    'error' => $trimmed !== '' ? $trimmed : 'unosms_send_failed',
                ];
            }
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            return [
                'ok' => false,
                'error' => $trimmed !== '' ? $trimmed : 'unosms_send_failed',
            ];
        }

        // HTTP 2xx with an unknown body: treat as success (SMS gateways vary widely).
        return ['ok' => true];
    }
}
