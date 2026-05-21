<?php
// app/Services/WhatsAppNodeCampaignOtpService.php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WhatsAppNodeCampaignOtpService
{
    public function ttlSeconds(): int
    {
        return (int) env('OTP_TTL_SECONDS', 300);
    }

    private function pepper(): string
    {
        return (string) env('OTP_PEPPER', '');
    }

    private function nodeUrl(): string
    {
        return rtrim((string) env('WHATSAPP_NODE_URL', ''), '/');
    }

    private function nodeToken(): string
    {
        return (string) env('WHATSAPP_NODE_TOKEN', '');
    }

    private function clientId(): string
    {
        return (string) env('WHATSAPP_NODE_CLIENT_ID', '');
    }

    public function buildOtpToken(string $purpose, string $phoneE164, int $code): string
    {
        $pepper = $this->pepper();
        if ($pepper === '') {
            throw new \RuntimeException('OTP_PEPPER missing');
        }

        $payload = [
            'v' => 1,
            'purpose' => $purpose, // forgot_password
            'phone_e164' => $phoneE164,
            'code_hash' => hash('sha256', $code . '|' . $pepper),
            'exp' => now()->addSeconds($this->ttlSeconds())->timestamp,
        ];

        return Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public function verifyOtpToken(string $token, string $purpose, string $phoneE164, string $code): array
    {
        $pepper = $this->pepper();
        if ($pepper === '') return ['ok' => false, 'error' => 'otp_not_configured'];

        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'invalid_token'];
        }

        if (($payload['purpose'] ?? null) !== $purpose) return ['ok' => false, 'error' => 'wrong_purpose'];
        if ((string)($payload['phone_e164'] ?? '') !== $phoneE164) return ['ok' => false, 'error' => 'wrong_phone'];
        if ((int)($payload['exp'] ?? 0) < now()->timestamp) return ['ok' => false, 'error' => 'expired'];

        $expected = (string)($payload['code_hash'] ?? '');
        $actual = hash('sha256', $code . '|' . $pepper);

        if (!hash_equals($expected, $actual)) return ['ok' => false, 'error' => 'invalid_code'];

        return ['ok' => true];
    }

    // second-step token after OTP verification
  public function buildVerifiedToken(string $purpose, string $phoneE164): string
{
    $payload = [
        'v' => 1,
        'purpose' => $purpose, // forgot_password_verified
        'phone_e164' => $phoneE164,
        'exp' => now()->addSeconds($this->ttlSeconds())->timestamp,
    ];
    return \Illuminate\Support\Facades\Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE));
}

   public function verifyVerifiedToken(string $token, string $purpose, string $phoneE164): array
{
    try {
        $payload = json_decode(
            \Illuminate\Support\Facades\Crypt::decryptString($token),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'invalid_token'];
    }
    if (($payload['purpose'] ?? null) !== $purpose) return ['ok' => false, 'error' => 'wrong_purpose'];
    if ((string)($payload['phone_e164'] ?? '') !== $phoneE164) return ['ok' => false, 'error' => 'wrong_phone'];
    if ((int)($payload['exp'] ?? 0) < now()->timestamp) return ['ok' => false, 'error' => 'expired'];
    return ['ok' => true];
}
    // campaign flow using {code}
    public function sendOtpViaNodeCampaign(string $phoneE164, int $code): array
    {
        $url = $this->nodeUrl();
        $token = $this->nodeToken();
        $clientId = $this->clientId();

        if ($url === '' || $token === '' || $clientId === '') {
            return ['ok' => false, 'error' => 'node_not_configured'];
        }

        $campaignName = 'otp_' . str_replace('-', '', (string) Str::uuid());
        $message = 'Your password reset code is {code}. It expires in 5 minutes. Do not share it.';

        // 1) create campaign
        $create = Http::withToken($token)->acceptJson()->asJson()->timeout(20)
            ->post($url . '/api/campaigns', [
                'name' => $campaignName,
                'message' => $message,
                'clientId' => $clientId,
            ]);

        if (!$create->successful()) {
            return [
                'ok' => false,
                'error' => 'campaign_create_failed',
                'http' => $create->status(),
                'body' => $create->json() ?? $create->body(),
            ];
        }

        $campaignId = data_get($create->json(), 'campaign._id')
            ?? data_get($create->json(), 'campaign.id');

        if (!$campaignId) {
            return ['ok' => false, 'error' => 'no_campaign_id', 'body' => $create->json()];
        }

        // 2) add one contact + code variable
        $add = Http::withToken($token)->acceptJson()->asJson()->timeout(20)
            ->post($url . '/api/contacts/' . $campaignId . '/add', [
                'phone' => $phoneE164,
                'name' => 'User',
                'code' => (string) $code, // replaces {code}
            ]);

        if (!$add->successful()) {
            return [
                'ok' => false,
                'error' => 'contact_add_failed',
                'http' => $add->status(),
                'body' => $add->json() ?? $add->body(),
            ];
        }

        // 3) start campaign
        $start = Http::withToken($token)->acceptJson()->asJson()->timeout(20)
            ->post($url . '/api/campaigns/' . $campaignId . '/start', []);

        if (!$start->successful()) {
            return [
                'ok' => false,
                'error' => 'campaign_start_failed',
                'http' => $start->status(),
                'body' => $start->json() ?? $start->body(),
            ];
        }

        return [
            'ok' => true,
            'campaign' => $campaignName,
            'campaignId' => (string) $campaignId,
        ];
    }
}