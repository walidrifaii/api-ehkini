<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
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

    private function nodeUrl(): string
    {
        return rtrim((string) config('otp.whatsapp_node.url', ''), '/');
    }

    private function nodeToken(): string
    {
        return (string) config('otp.whatsapp_node.token', '');
    }

    private function clientId(): string
    {
        return (string) config('otp.whatsapp_node.client_id', '');
    }

    private function deliveryMode(): string
    {
        return strtolower((string) config('otp.whatsapp_node.delivery', 'otp'));
    }

    private function timeout(): int
    {
        return max(1, (int) config('otp.whatsapp_node.timeout', 25));
    }

    private function connectTimeout(): int
    {
        return max(1, (int) config('otp.whatsapp_node.connect_timeout', 5));
    }

    private function httpClient(?int $timeout = null): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->nodeToken())
            ->acceptJson()
            ->asJson()
            ->timeout($timeout ?? $this->timeout())
            ->connectTimeout($this->connectTimeout());
    }

    /**
     * @return array{ok: false, error: string, http?: int, body?: mixed}|array{ok: true}
     */
    private function nodeResponseFailure(Response $response): array
    {
        return [
            'ok' => false,
            'error' => $response->json('error') ?? 'WhatsApp delivery failed',
            'http' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ];
    }

    public function buildOtpToken(string $purpose, string $phoneE164, int $code): string
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

    public function sendOtpViaNodeCampaign(string $phoneE164, int $code): array
    {
        $url = $this->nodeUrl();
        $token = $this->nodeToken();
        $clientId = $this->clientId();

        if ($url === '' || $token === '' || $clientId === '') {
            return ['ok' => false, 'error' => 'node_not_configured'];
        }

        if ($this->deliveryMode() === 'campaign') {
            return $this->sendOtpViaCampaignFlow($phoneE164, $code, $clientId);
        }

        return $this->sendOtpDirect($phoneE164, $code, $clientId);
    }

    private function sendOtpDirect(string $phoneE164, int $code, string $clientId): array
    {
        try {
            $response = $this->httpClient()
                ->post($this->nodeUrl() . '/api/otp/send', [
                    'phone' => $phoneE164,
                    'code' => (string) $code,
                    'clientId' => $clientId,
                    'message' => 'Your verification code is ' . $code . '. It expires in 5 minutes.',
                ]);

            if ($response->failed() || ! ($response->json('ok') ?? false)) {
                return $this->nodeResponseFailure($response);
            }

            return ['ok' => true];
        } catch (ConnectionException $e) {
            return ['ok' => false, 'error' => 'WhatsApp service is not reachable'];
        }
    }

    private function sendOtpViaCampaignFlow(string $phoneE164, int $code, string $clientId): array
    {
        $campaignName = 'otp_' . str_replace('-', '', (string) Str::uuid());
        $message = 'Your verification code is {code}. It expires in 5 minutes. Do not share it.';

        try {
            $create = $this->httpClient()->post($this->nodeUrl() . '/api/campaigns', [
                'name' => $campaignName,
                'message' => $message,
                'clientId' => $clientId,
            ]);

            if ($create->failed() || ! ($create->json('ok') ?? $create->successful())) {
                return $this->nodeResponseFailure($create);
            }

            $campaignId = data_get($create->json(), 'campaign._id')
                ?? data_get($create->json(), 'campaign.id');

            if (! $campaignId) {
                return ['ok' => false, 'error' => 'no_campaign_id', 'body' => $create->json()];
            }

            $add = $this->httpClient()->post($this->nodeUrl() . '/api/contacts/' . $campaignId . '/add', [
                'phone' => $phoneE164,
                'name' => 'User',
                'code' => (string) $code,
            ]);

            if ($add->failed() || ! ($add->json('ok') ?? $add->successful())) {
                return $this->nodeResponseFailure($add);
            }

            $start = $this->httpClient()->post($this->nodeUrl() . '/api/campaigns/' . $campaignId . '/start', []);

            if ($start->failed() || ! ($start->json('ok') ?? $start->successful())) {
                return $this->nodeResponseFailure($start);
            }

            return [
                'ok' => true,
                'campaign' => $campaignName,
                'campaignId' => (string) $campaignId,
            ];
        } catch (ConnectionException $e) {
            return ['ok' => false, 'error' => 'WhatsApp service is not reachable'];
        }
    }
}
