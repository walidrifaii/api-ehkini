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

    private function timeoutSeconds(): int
    {
        return max(5, (int) config('otp.whatsapp_node.timeout_seconds', 30));
    }

    private function campaignStartTimeoutSeconds(): int
    {
        return max(5, (int) config('otp.whatsapp_node.campaign_start_timeout_seconds', 90));
    }

    /**
     * @return array{response: Response|null, connection_error: string|null}
     */
    private function postToNode(string $path, array $body, ?int $timeoutSeconds = null): array
    {
        $timeout = $timeoutSeconds ?? $this->timeoutSeconds();

        try {
            $response = Http::withToken($this->nodeToken())
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->post($this->nodeUrl() . $path, $body);

            return ['response' => $response, 'connection_error' => null];
        } catch (ConnectionException $e) {
            return ['response' => null, 'connection_error' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: false, error: string, detail?: string, http?: int, body?: mixed}|array{ok: true}
     */
    private function connectionFailure(string $error, string $detail): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'detail' => $detail,
        ];
    }

    /**
     * @return array{ok: false, error: string, http?: int, body?: mixed}|array{ok: true}
     */
    private function httpFailure(string $error, Response $response): array
    {
        return [
            'ok' => false,
            'error' => $error,
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

        if ($this->deliveryMode() !== 'campaign') {
            return $this->sendOtpDirect($phoneE164, $code, $clientId);
        }

        return $this->sendOtpViaCampaignFlow($phoneE164, $code, $clientId);
    }

    private function sendOtpDirect(string $phoneE164, int $code, string $clientId): array
    {
        $result = $this->postToNode('/api/otp/send', [
            'phone' => $phoneE164,
            'code' => (string) $code,
            'clientId' => $clientId,
        ]);

        if ($result['connection_error'] !== null) {
            return $this->connectionFailure('otp_send_timeout', $result['connection_error']);
        }

        $response = $result['response'];
        if (! $response->successful()) {
            return $this->httpFailure('otp_send_failed', $response);
        }

        return ['ok' => true];
    }

    private function sendOtpViaCampaignFlow(string $phoneE164, int $code, string $clientId): array
    {
        $campaignName = 'otp_' . str_replace('-', '', (string) Str::uuid());
        $message = 'Your verification code is {code}. It expires in 5 minutes. Do not share it.';

        $create = $this->postToNode('/api/campaigns', [
            'name' => $campaignName,
            'message' => $message,
            'clientId' => $clientId,
        ]);

        if ($create['connection_error'] !== null) {
            return $this->connectionFailure('campaign_create_timeout', $create['connection_error']);
        }

        $createResponse = $create['response'];
        if (! $createResponse->successful()) {
            return $this->httpFailure('campaign_create_failed', $createResponse);
        }

        $campaignId = data_get($createResponse->json(), 'campaign._id')
            ?? data_get($createResponse->json(), 'campaign.id');

        if (! $campaignId) {
            return ['ok' => false, 'error' => 'no_campaign_id', 'body' => $createResponse->json()];
        }

        $add = $this->postToNode('/api/contacts/' . $campaignId . '/add', [
            'phone' => $phoneE164,
            'name' => 'User',
            'code' => (string) $code,
        ]);

        if ($add['connection_error'] !== null) {
            return $this->connectionFailure('contact_add_timeout', $add['connection_error']);
        }

        $addResponse = $add['response'];
        if (! $addResponse->successful()) {
            return $this->httpFailure('contact_add_failed', $addResponse);
        }

        $start = $this->postToNode(
            '/api/campaigns/' . $campaignId . '/start',
            [],
            $this->campaignStartTimeoutSeconds(),
        );

        if ($start['connection_error'] !== null) {
            return $this->connectionFailure('campaign_start_timeout', $start['connection_error']);
        }

        $startResponse = $start['response'];
        if (! $startResponse->successful()) {
            return $this->httpFailure('campaign_start_failed', $startResponse);
        }

        return [
            'ok' => true,
            'campaign' => $campaignName,
            'campaignId' => (string) $campaignId,
        ];
    }
}
