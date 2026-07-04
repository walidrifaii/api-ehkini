<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppNodeCampaignOtpService
{
    public function ttlSeconds(): int
    {
        return (int) config('otp.ttl_seconds', 300);
    }

    private function pepper(): string
    {
        return (string) config('otp.pepper', '');
    }

    private function nodeHttp(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken((string) config('otp.whatsapp_node.token'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('otp.whatsapp_node.timeout', 25))
            ->connectTimeout((int) config('otp.whatsapp_node.connect_timeout', 5));
    }

    private function isConfigured(): bool
    {
        $cfg = config('otp.whatsapp_node');

        return ($cfg['url'] ?? '') !== ''
            && ($cfg['token'] ?? '') !== ''
            && ($cfg['client_id'] ?? '') !== '';
    }

    public function formatPhoneForNode(string $phoneE164): string
    {
        $format = strtoupper((string) config('otp.whatsapp_node.phone_format', 'E164'));
        $digits = preg_replace('/\D+/', '', $phoneE164) ?? '';

        if ($format === 'DIGITS') {
            return $digits;
        }

        return str_starts_with($phoneE164, '+') ? $phoneE164 : '+' . $digits;
    }

    public function buildOtpToken(string $purpose, string $phoneE164, string $code): string
    {
        $pepper = $this->pepper();
        if ($pepper === '') {
            throw new \RuntimeException('OTP_PEPPER missing');
        }

        $payload = [
            'v' => 1,
            'purpose' => $purpose,
            'phone_e164' => $phoneE164,
            'code_hash' => hash('sha256', $code . '|' . $pepper),
            'exp' => now()->addSeconds($this->ttlSeconds())->timestamp,
        ];

        return Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public function verifyOtpToken(string $token, string $purpose, string $phoneE164, string $code): array
    {
        $pepper = $this->pepper();
        if ($pepper === '') {
            return ['ok' => false, 'error' => 'otp_not_configured'];
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'invalid_token'];
        }

        if (($payload['purpose'] ?? null) !== $purpose) {
            return ['ok' => false, 'error' => 'wrong_purpose'];
        }
        if ((string) ($payload['phone_e164'] ?? '') !== $phoneE164) {
            return ['ok' => false, 'error' => 'wrong_phone'];
        }
        if ((int) ($payload['exp'] ?? 0) < now()->timestamp) {
            return ['ok' => false, 'error' => 'expired'];
        }

        $expected = (string) ($payload['code_hash'] ?? '');
        $actual = hash('sha256', $code . '|' . $pepper);

        if (! hash_equals($expected, $actual)) {
            return ['ok' => false, 'error' => 'invalid_code'];
        }

        return ['ok' => true];
    }

    public function buildVerifiedToken(string $purpose, string $phoneE164): string
    {
        $payload = [
            'v' => 1,
            'purpose' => $purpose,
            'phone_e164' => $phoneE164,
            'exp' => now()->addSeconds($this->ttlSeconds())->timestamp,
        ];

        return Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public function verifyVerifiedToken(string $token, string $purpose, string $phoneE164): array
    {
        try {
            $payload = json_decode(
                Crypt::decryptString($token),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'invalid_token'];
        }

        if (($payload['purpose'] ?? null) !== $purpose) {
            return ['ok' => false, 'error' => 'wrong_purpose'];
        }
        if ((string) ($payload['phone_e164'] ?? '') !== $phoneE164) {
            return ['ok' => false, 'error' => 'wrong_phone'];
        }
        if ((int) ($payload['exp'] ?? 0) < now()->timestamp) {
            return ['ok' => false, 'error' => 'expired'];
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, error?: string, channel?: string, otp_token?: string, expires_in?: int, http?: int, body?: mixed}
     */
    public function sendOtpViaNodeCampaign(string $phoneE164, string $code, string $purpose = 'forgot_password'): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'node_not_configured'];
        }

        if (config('otp.whatsapp_node.delivery', 'otp') === 'otp') {
            return $this->sendOtpViaOtpEndpoint($phoneE164, $code, $purpose);
        }

        return $this->sendOtpViaLegacyCampaign($phoneE164, $code, $purpose);
    }

    /**
     * @return array{ok: bool, error?: string, channel?: string, otp_token?: string, expires_in?: int, http?: int, body?: mixed}
     */
    private function sendOtpViaOtpEndpoint(string $phoneE164, string $code, string $purpose): array
    {
        $url = rtrim((string) config('otp.whatsapp_node.url'), '/');
        $phone = $this->formatPhoneForNode($phoneE164);

        try {
            $response = $this->nodeHttp()->post($url . '/api/otp/send', [
                'phone' => $phone,
                'code' => $code,
                'clientId' => (string) config('otp.whatsapp_node.client_id'),
                'message' => 'Your verification code is ' . $code . '. It expires in 5 minutes. Do not share it.',
            ]);

            if ($response->failed() || ! ($response->json('ok') ?? false)) {
                return [
                    'ok' => false,
                    'error' => $response->json('error') ?? 'WhatsApp delivery failed',
                    'http' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ];
            }

            try {
                $otpToken = $this->buildOtpToken($purpose, $phoneE164, $code);
            } catch (\RuntimeException $e) {
                return ['ok' => false, 'error' => 'otp_pepper_missing'];
            }

            return [
                'ok' => true,
                'channel' => (string) ($response->json('channel') ?? 'whatsapp_node'),
                'otp_token' => $otpToken,
                'expires_in' => (int) ($response->json('expires_in') ?? $this->ttlSeconds()),
            ];
        } catch (ConnectionException $e) {
            Log::error('WhatsApp Node unreachable', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'WhatsApp service is not reachable'];
        }
    }

    /**
     * @return array{ok: bool, error?: string, channel?: string, otp_token?: string, expires_in?: int, http?: int, body?: mixed, campaign?: string, campaignId?: string}
     */
    private function sendOtpViaLegacyCampaign(string $phoneE164, string $code, string $purpose): array
    {
        $url = rtrim((string) config('otp.whatsapp_node.url'), '/');
        $clientId = (string) config('otp.whatsapp_node.client_id');
        $phone = $this->formatPhoneForNode($phoneE164);
        $campaignName = 'otp_' . str_replace('-', '', (string) Str::uuid());
        $message = 'Your verification code is {code}. It expires in 5 minutes. Do not share it.';

        try {
            $create = $this->nodeHttp()->post($url . '/api/campaigns', [
                'name' => $campaignName,
                'message' => $message,
                'clientId' => $clientId,
            ]);

            if ($this->isNodeFailure($create)) {
                return $this->nodeFailure($create);
            }

            $campaignId = data_get($create->json(), 'campaign._id')
                ?? data_get($create->json(), 'campaign.id');

            if (! $campaignId) {
                return ['ok' => false, 'error' => 'no_campaign_id', 'body' => $create->json()];
            }

            $add = $this->nodeHttp()->post($url . '/api/contacts/' . $campaignId . '/add', [
                'phone' => $phone,
                'name' => 'User',
                'code' => $code,
            ]);

            if ($this->isNodeFailure($add)) {
                return $this->nodeFailure($add);
            }

            $start = $this->nodeHttp()->post($url . '/api/campaigns/' . $campaignId . '/start', []);

            if ($this->isNodeFailure($start)) {
                return $this->nodeFailure($start);
            }

            try {
                $otpToken = $this->buildOtpToken($purpose, $phoneE164, $code);
            } catch (\RuntimeException $e) {
                return ['ok' => false, 'error' => 'otp_pepper_missing'];
            }

            return [
                'ok' => true,
                'channel' => 'whatsapp_node',
                'otp_token' => $otpToken,
                'expires_in' => $this->ttlSeconds(),
                'campaign' => $campaignName,
                'campaignId' => (string) $campaignId,
            ];
        } catch (ConnectionException $e) {
            Log::error('WhatsApp Node unreachable', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'WhatsApp service is not reachable'];
        }
    }

    private function isNodeFailure(Response $response): bool
    {
        if ($response->failed()) {
            return true;
        }

        $ok = $response->json('ok');
        if ($ok === null) {
            return false;
        }

        return ! $ok;
    }

    /**
     * @return array{ok: false, error: string, http?: int, body?: mixed}
     */
    private function nodeFailure(Response $response): array
    {
        return [
            'ok' => false,
            'error' => $response->json('error') ?? 'WhatsApp delivery failed',
            'http' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ];
    }
}
