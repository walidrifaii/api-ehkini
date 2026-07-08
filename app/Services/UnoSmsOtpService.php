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
     * UnoSMS Lebanon delivery works best with national format (71887115),
     * not international (96171887115). Configurable via UNOSMS_PHONE_FORMAT.
     */
    public function formatToNumber(string $phoneE164): string
    {
        $digits = $this->phoneDigits($phoneE164);
        if ($digits === '') {
            return '';
        }

        $format = strtolower((string) config('otp.unosms.phone_format', 'national'));
        if ($format === 'international') {
            return $digits;
        }

        foreach ($this->countryCodes() as $cc) {
            if ($cc !== '' && str_starts_with($digits, $cc) && strlen($digits) > strlen($cc)) {
                return ltrim(substr($digits, strlen($cc)), '0');
            }
        }

        return ltrim($digits, '0');
    }

    /**
     * @return array{ok: bool, error?: string, channel?: string, otp_token?: string, expires_in?: int, http?: int, body?: string, detail?: string, to?: string}
     */
    public function sendOtp(string $phoneE164, string $code, string $purpose = 'register'): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'unosms_not_configured'];
        }

        $toNumber = $this->formatToNumber($phoneE164);
        if ($toNumber === '') {
            return ['ok' => false, 'error' => 'invalid_phone'];
        }

        $send = $this->dispatchSend($toNumber, $code);
        if (! ($send['ok'] ?? false)) {
            $international = $this->phoneDigits($phoneE164);
            if ($international !== '' && $international !== $toNumber) {
                Log::info('unosms.retry_international', [
                    'from' => $toNumber,
                    'to' => $international,
                ]);
                $send = $this->dispatchSend($international, $code);
            }
        }

        return $send;
    }

    /**
     * @return array{ok: bool, error?: string, http?: int, body?: string, detail?: string, to?: string}
     */
    private function dispatchSend(string $toNumber, string $code): array
    {
        $cfg = config('otp.unosms');
        $message = 'Your verification code is ' . $code . '. Valid for 5 minutes.';

        $params = [
            'user' => (string) $cfg['user'],
            'pass' => (string) $cfg['pass'],
            'to' => $toNumber,
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
                'to' => $toNumber,
                'to_last4' => substr($toNumber, -4),
            ]);

            return [
                'ok' => false,
                'error' => 'SMS service is not reachable',
                'detail' => $e->getMessage(),
                'to' => $toNumber,
            ];
        }

        $body = trim((string) $response->body());
        $parsed = $this->parseSendResponse($response->status(), $body);

        if (! ($parsed['ok'] ?? false)) {
            Log::warning('unosms.send_failed', [
                'http' => $response->status(),
                'body' => $body,
                'to' => $toNumber,
                'to_last4' => substr($toNumber, -4),
            ]);

            return [
                'ok' => false,
                'error' => (string) ($parsed['error'] ?? ($body !== '' ? $body : 'unosms_send_failed')),
                'http' => $response->status(),
                'body' => $body,
                'to' => $toNumber,
            ];
        }

        Log::info('unosms.send_ok', [
            'http' => $response->status(),
            'body' => $body,
            'to' => $toNumber,
            'to_last4' => substr($toNumber, -4),
        ]);

        return [
            'ok' => true,
            'body' => $body,
            'to' => $toNumber,
        ];
    }

    /**
     * Parse UnoSMS gateway body. Example success:
     * "OK:1 ; Total: 1; Invalid: 0; Total cost: 0.024; Credit remaining: 7.299"
     *
     * @return array{ok: bool, error?: string}
     */
    private function parseSendResponse(int $httpStatus, string $body): array
    {
        $trimmed = trim($body);
        $lower = strtolower($trimmed);

        if ($httpStatus < 200 || $httpStatus >= 300) {
            return [
                'ok' => false,
                'error' => $trimmed !== '' ? $trimmed : 'unosms_send_failed',
            ];
        }

        if (preg_match('/OK:\s*(\d+)\s*;.*?Invalid:\s*(\d+)/i', $trimmed, $m)) {
            $okCount = (int) $m[1];
            $invalidCount = (int) $m[2];

            if ($okCount >= 1 && $invalidCount === 0) {
                return ['ok' => true];
            }

            return [
                'ok' => false,
                'error' => $trimmed !== ''
                    ? $trimmed
                    : 'unosms_rejected_number',
            ];
        }

        if ($trimmed === '') {
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
            '/invalid:\s*[1-9]/i',
        ];

        foreach ($failurePatterns as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return [
                    'ok' => false,
                    'error' => $trimmed !== '' ? $trimmed : 'unosms_send_failed',
                ];
            }
        }

        return ['ok' => true];
    }
}
