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
        $pepper = trim((string) config('otp.pepper', ''));
        if ($pepper !== '') {
            return $pepper;
        }

        return trim((string) config('app.key', ''));
    }

    private function nodeHttp(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken((string) config('otp.whatsapp_node.token'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('otp.whatsapp_node.timeout', 35))
            ->connectTimeout((int) config('otp.whatsapp_node.connect_timeout', 5));
    }

    private function isConfigured(): bool
    {
        $cfg = config('otp.whatsapp_node');

        return ($cfg['url'] ?? '') !== ''
            && ($cfg['token'] ?? '') !== ''
            && ($cfg['client_id'] ?? '') !== '';
    }

    private function nodeUrl(): string
    {
        return rtrim((string) config('otp.whatsapp_node.url'), '/');
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

    private function otpMessage(string $code): string
    {
        return 'Your verification code for Ehkini App is ' . $code . '. Valid for 5 minutes.';
    }

    /**
     * Body for POST /api/otp/send — phone (digits only), code, clientId.
     *
     * @return array{phone: string, code: string, clientId: string}
     */
    private function otpRequestBody(string $phone, string $code): array
    {
        return [
            'phone' => $phone,
            'code' => $code,
            'clientId' => (string) config('otp.whatsapp_node.client_id'),
        ];
    }

    public function buildOtpToken(string $purpose, string $phoneE164, string $code): string
    {
        $pepper = $this->pepper();
        if ($pepper === '') {
            throw new \RuntimeException('OTP_PEPPER missing');
        }

-        $code = $this->normalizeOtpCode($code);
        $canonicalPhone = $this->canonicalPhoneE164($phoneE164);

        $payload = [
            'v' => 1,
            'purpose' => $purpose,
            'phone_e164' => $canonicalPhone,
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

        $code = $this->normalizeOtpCode($code);
        if (strlen($code) !== 6) {
            return ['ok' => false, 'error' => 'invalid_code'];
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'invalid_token'];
        }

        if (($payload['purpose'] ?? null) !== $purpose) {
            return ['ok' => false, 'error' => 'wrong_purpose'];
        }
        if (! $this->phonesMatch((string) ($payload['phone_e164'] ?? ''), $phoneE164)) {
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
            'phone_e164' => $this->canonicalPhoneE164($phoneE164),
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
        if (! $this->phonesMatch((string) ($payload['phone_e164'] ?? ''), $phoneE164)) {
            return ['ok' => false, 'error' => 'wrong_phone'];
        }
        if ((int) ($payload['exp'] ?? 0) < now()->timestamp) {
            return ['ok' => false, 'error' => 'expired'];
        }

        return ['ok' => true];
    }

    private function normalizeOtpCode(string $code): string
    {
        return substr(preg_replace('/\D+/', '', trim($code)) ?? '', 0, 6);
    }

    private function phoneDigits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '961')) {
            $national = ltrim(substr($digits, 3), '0');

            return '961' . $national;
        }

        return ltrim($digits, '0');
    }

    private function phonesMatch(string $phoneA, string $phoneB): bool
    {
        $digitsA = $this->phoneDigits($phoneA);
        $digitsB = $this->phoneDigits($phoneB);

        return $digitsA !== '' && $digitsA === $digitsB;
    }

    private function canonicalPhoneE164(string $phoneE164): string
    {
        $digits = $this->phoneDigits($phoneE164);
        if ($digits === '') {
            return $phoneE164;
        }

        return '+' . $digits;
    }

    /**
     * @return array{ok: bool, error?: string, channel?: string, otp_token?: string, expires_in?: int, http?: int, body?: mixed, campaign?: string, campaignId?: string}
     */
    public function sendOtpViaNodeCampaign(string $phoneE164, string $code, string $purpose = 'forgot_password'): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'node_not_configured'];
        }

        $delivery = strtolower((string) config('otp.whatsapp_node.delivery', 'otp'));

        return match ($delivery) {
            'campaign' => $this->sendOtpViaLegacyCampaign($phoneE164, $code, $purpose),
            'send-campaign', 'send_campaign' => $this->sendOtpViaSendCampaignEndpoint($phoneE164, $code, $purpose),
            default => $this->sendOtpViaOtpEndpoint($phoneE164, $code, $purpose),
        };
    }

    /**
     * @return array{ok: bool, error?: string, channel?: string, otp_token?: string, expires_in?: int, http?: int, body?: mixed}
     */
    private function sendOtpViaOtpEndpoint(string $phoneE164, string $code, string $purpose): array
    {
        return $this->postOtpEndpoint('/api/otp/send', $phoneE164, $code, $purpose);
    }

    /**
     * @return array{ok: bool, error?: string, channel?: string, otp_token?: string, expires_in?: int, http?: int, body?: mixed}
     */
    private function sendOtpViaSendCampaignEndpoint(string $phoneE164, string $code, string $purpose): array
    {
        return $this->postOtpEndpoint('/api/otp/send-campaign', $phoneE164, $code, $purpose);
    }

    /**
     * @return array{ok: bool, error?: string, channel?: string, otp_token?: string, expires_in?: int, http?: int, body?: mixed}
     */
    private function postOtpEndpoint(string $path, string $phoneE164, string $code, string $purpose): array
    {
        $phone = $this->formatPhoneForNode($phoneE164);

        try {
            $response = $this->nodeHttp()->post(
                $this->nodeUrl() . $path,
                $this->otpRequestBody($phone, $code),
            );

            return $this->buildResultFromResponse($response, $phoneE164, $code, $purpose);
        } catch (ConnectionException $e) {
            Log::error('WhatsApp Node unreachable', ['error' => $e->getMessage(), 'path' => $path]);

            return ['ok' => false, 'error' => 'WhatsApp service is not reachable'];
        } catch (\Throwable $e) {
            Log::error('WhatsApp Node OTP request failed', [
                'error' => $e->getMessage(),
                'path' => $path,
            ]);

            return ['ok' => false, 'error' => 'WhatsApp service is not reachable'];
        }
    }

    /**
     * @return array{ok: bool, error?: string, channel?: string, otp_token?: string, expires_in?: int, http?: int, body?: mixed, campaign?: string, campaignId?: string}
     */
    private function sendOtpViaLegacyCampaign(string $phoneE164, string $code, string $purpose): array
    {
        $clientId = (string) config('otp.whatsapp_node.client_id');
        $phone = $this->formatPhoneForNode($phoneE164);
        $campaignName = 'otp_' . $purpose . '_' . str_replace('-', '', (string) Str::uuid());
        $message = 'Your verification code is {code}. Valid for 5 minutes.';

        try {
            $create = $this->nodeHttp()->post($this->nodeUrl() . '/api/campaigns', [
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

            $add = $this->nodeHttp()->post($this->nodeUrl() . '/api/contacts/' . $campaignId . '/add', [
                'phone' => $phone,
                'code' => $code,
            ]);

            if ($this->isNodeFailure($add)) {
                return $this->nodeFailure($add);
            }

            $start = $this->nodeHttp()->post($this->nodeUrl() . '/api/campaigns/' . $campaignId . '/start', []);

            if ($this->isNodeFailure($start)) {
                return $this->nodeFailure($start);
            }

            $result = $this->buildSuccessResult($phoneE164, $code, $purpose);
            if (! ($result['ok'] ?? false)) {
                return $result;
            }

            $result['campaign'] = $campaignName;
            $result['campaignId'] = (string) $campaignId;

            return $result;
        } catch (ConnectionException $e) {
            Log::error('WhatsApp Node unreachable', ['error' => $e->getMessage(), 'flow' => 'campaign']);

            return ['ok' => false, 'error' => 'WhatsApp service is not reachable'];
        } catch (\Throwable $e) {
            Log::error('WhatsApp Node campaign OTP failed', [
                'error' => $e->getMessage(),
                'flow' => 'campaign',
            ]);

            return ['ok' => false, 'error' => 'WhatsApp service is not reachable'];
        }
    }

    /**
     * @return array{ok: bool, error?: string, channel?: string, otp_token?: string, expires_in?: int, http?: int, body?: mixed}
     */
    private function buildResultFromResponse(Response $response, string $phoneE164, string $code, string $purpose): array
    {
        if ($response->failed() || ! ($response->json('ok') ?? false)) {
            Log::warning('WhatsApp Node OTP send failed', [
                'http' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return [
                'ok' => false,
                'error' => $response->json('error') ?? 'WhatsApp delivery failed',
                'http' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        }

        $result = $this->buildSuccessResult($phoneE164, $code, $purpose, $response);

        return ($result['ok'] ?? false)
            ? $result
            : ['ok' => false, 'error' => $result['error'] ?? 'otp_pepper_missing'];
    }

    /**
     * @return array{ok: true, channel: string, otp_token: string, expires_in: int}
     */
    private function buildSuccessResult(
        string $phoneE164,
        string $code,
        string $purpose,
        ?Response $response = null,
    ): array {
        try {
            $otpToken = $this->buildOtpToken($purpose, $phoneE164, $code);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => 'otp_pepper_missing'];
        }

        return [
            'ok' => true,
            'channel' => (string) ($response?->json('channel') ?? 'whatsapp_node'),
            'otp_token' => $otpToken,
            'expires_in' => (int) ($response?->json('expires_in') ?? $this->ttlSeconds()),
        ];
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
