<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OtpDeliveryService
{
    public function __construct(
        private readonly WhatsAppNodeCampaignOtpService $whatsAppNode,
        private readonly MessageCentralOtpService $messageCentral,
        private readonly UnoSmsOtpService $unoSms,
    ) {}

    public function ttlSeconds(): int
    {
        return $this->whatsAppNode->ttlSeconds();
    }

    public function phoneE164ForRequest(string $countryCode, string $phone): string
    {
        $ccDigits = $this->messageCentral->countryCodeDigits($countryCode);
        $mobileDigits = $this->messageCentral->mobileNumberDigits($ccDigits, $phone);

        return $this->formatPhoneE164($ccDigits, $mobileDigits);
    }

    public function phonesMatch(string $phoneA, string $phoneB): bool
    {
        $digitsA = $this->phoneDigits($phoneA);
        $digitsB = $this->phoneDigits($phoneB);

        return $digitsA !== '' && $digitsA === $digitsB;
    }

    public function normalizeOtpCode(string $code): string
    {
        return substr(preg_replace('/\D+/', '', trim($code)) ?? '', 0, 6);
    }

    public function phoneDigits(string $phone): string
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

    public function isWhatsAppNodeAvailable(): bool
    {
        $cfg = config('otp.whatsapp_node');

        return ($cfg['enabled'] ?? false)
            && ($cfg['url'] ?? '') !== ''
            && ($cfg['token'] ?? '') !== ''
            && ($cfg['client_id'] ?? '') !== '';
    }

    public function isSmsAvailable(): bool
    {
        return $this->messageCentral->isSmsConfigured()
            || $this->unoSms->isConfigured();
    }

    public function isSmsAvailableForCountry(string $countryCode): bool
    {
        $ccDigits = $this->messageCentral->countryCodeDigits($countryCode);

        if ($this->unoSms->isLebanon($ccDigits)) {
            return $this->unoSms->isConfigured();
        }

        return $this->messageCentral->isSmsConfigured();
    }

    public function isMessageCentralWhatsAppAvailable(): bool
    {
        return $this->messageCentral->isWhatsAppConfigured();
    }

    /**
     * @return array{ok:bool, otp_token?:string, channel?:string, expires_in?:int, error?:string, attempts?:array<int,array<string,mixed>>}
     */
    public function sendOtp(
        string $purpose,
        string $countryCode,
        string $mobileNumber,
        ?string $channel = null,
    ): array {
        $ccDigits = $this->messageCentral->countryCodeDigits($countryCode);
        $mobileDigits = $this->messageCentral->mobileNumberDigits($ccDigits, $mobileNumber);
        $phoneE164 = $this->formatPhoneE164($ccDigits, $mobileDigits);
        $attempts = [];

        $channels = $this->resolveSendChannels($channel);
        if ($channels === []) {
            return [
                'ok' => false,
                'error' => 'otp_channels_not_configured',
                'hint' => 'Enable WhatsApp node, UnoSMS (Lebanon), and/or Message Central SMS in .env',
            ];
        }

        foreach ($channels as $resolvedChannel) {
            if ($resolvedChannel === 'whatsapp_node') {
                $code = (string) random_int(100000, 999999);

                try {
                    $send = $this->whatsAppNode->sendOtpViaNodeCampaign($phoneE164, $code, $purpose);
                } catch (\Throwable $e) {
                    $send = [
                        'ok' => false,
                        'error' => 'WhatsApp service is not reachable',
                        'detail' => $e->getMessage(),
                    ];
                }

                $attempts[] = ['channel' => $resolvedChannel, 'result' => $send];

                if ($send['ok'] ?? false) {
                    return [
                        'ok' => true,
                        'otp_token' => $send['otp_token'],
                        'channel' => $send['channel'] ?? $resolvedChannel,
                        'expires_in' => $send['expires_in'] ?? $this->ttlSeconds(),
                    ];
                }

                continue;
            }

            $send = $this->sendSmsOtp($purpose, $ccDigits, $mobileDigits, $phoneE164);
            $attempts[] = ['channel' => $resolvedChannel, 'result' => $send];

            if ($send['ok'] ?? false) {
                return $this->finalizeSmsSendResult($send);
            }
        }

        $error = $this->summarizeAttempts($attempts);

        return [
            'ok' => false,
            'error' => $error ?? 'no_otp_channel_available',
            'error_summary' => $error,
            'attempts' => $attempts,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $attempts
     */
    private function summarizeAttempts(array $attempts): ?string
    {
        foreach ($attempts as $attempt) {
            $result = $attempt['result'] ?? [];
            if (! empty($result['error'])) {
                return (string) $result['error'];
            }
            if (! empty($result['error_summary'])) {
                return (string) $result['error_summary'];
            }
            if (! empty($result['mc_message'])) {
                return (string) $result['mc_message'];
            }
        }

        return null;
    }

    public function verifyOtp(string $token, string $purpose, string $phoneE164, string $code): array
    {
        $code = $this->normalizeOtpCode($code);
        if (strlen($code) !== 6) {
            return ['ok' => false, 'error' => 'invalid_code'];
        }

        $payload = $this->decodeToken($token);
        if ($payload === null) {
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

        $provider = (string) ($payload['provider'] ?? 'local');

        if ($provider === 'message_central') {
            $context = [
                'country_code_digits' => (string) ($payload['country_code_digits'] ?? ''),
                'mobile_number' => (string) ($payload['mobile_number'] ?? ''),
                'auth_token' => (string) ($payload['mc_auth_token'] ?? ''),
            ];

            return $this->messageCentral->validateOtp(
                (string) ($payload['verification_id'] ?? ''),
                $code,
                (string) ($payload['flow_type'] ?? 'SMS'),
                $context,
            );
        }

        return $this->whatsAppNode->verifyOtpToken($token, $purpose, $phoneE164, $code);
    }

    public function buildVerifiedToken(string $purpose, string $phoneE164): string
    {
        return $this->whatsAppNode->buildVerifiedToken($purpose, $phoneE164);
    }

    public function verifyVerifiedToken(string $token, string $purpose, string $phoneE164): array
    {
        return $this->whatsAppNode->verifyVerifiedToken($token, $purpose, $phoneE164);
    }

    /**
     * @return list<string>
     */
    private function resolveSendChannels(?string $channel): array
    {
        $requested = strtolower(trim((string) $channel));
        $preferred = strtolower((string) config('otp.channel', 'auto'));

        $map = [
            // App "WhatsApp" button → WhatsApp node only (not Message Central).
            'whatsapp' => ['whatsapp_node'],
            'whatsapp_node' => ['whatsapp_node'],
            // App "SMS" button → UnoSMS (Lebanon) or Message Central (other countries).
            'sms' => ['sms'],
        ];

        if ($requested !== '' && isset($map[$requested])) {
            return $this->filterAvailableChannels($map[$requested]);
        }

        if ($preferred === 'whatsapp_node' || $preferred === 'whatsapp') {
            return $this->filterAvailableChannels(['whatsapp_node']);
        }

        if ($preferred === 'sms') {
            return $this->filterAvailableChannels(['sms']);
        }

        // auto without explicit channel: try WhatsApp node, then SMS.
        return $this->filterAvailableChannels(['whatsapp_node', 'sms']);
    }

    /**
     * @param  list<string>  $channels
     * @return list<string>
     */
    private function filterAvailableChannels(array $channels): array
    {
        $available = [];

        foreach ($channels as $channel) {
            if ($channel === 'whatsapp_node' && $this->isWhatsAppNodeAvailable()) {
                $available[] = $channel;
            }
            if ($channel === 'sms' && $this->isSmsAvailable()) {
                $available[] = $channel;
            }
        }

        return $available;
    }

    /**
     * HTTP JSON body for OTP send endpoints (matches deployed app / Message Central shape).
     *
     * @param  array<string, mixed>  $send
     * @return array<string, mixed>
     */
    public function buildOtpSendHttpResponse(array $send, string $message = 'OTP sent.'): array
    {
        return [
            'success' => true,
            'message' => $message,
            'otp_token' => (string) ($send['otp_token'] ?? ''),
            'verification_id' => $send['verification_id'] ?? null,
            'channel' => $send['channel'] ?? 'sms',
            'expires_in' => (int) ($send['expires_in'] ?? $this->ttlSeconds()),
        ];
    }

    /**
     * @param  array<string, mixed>  $send
     * @return array{ok: true, otp_token: string, verification_id: string, channel: string, expires_in: int}
     */
    private function finalizeSmsSendResult(array $send): array
    {
        return [
            'ok' => true,
            'otp_token' => (string) ($send['otp_token'] ?? ''),
            'verification_id' => (string) ($send['verification_id'] ?? ''),
            'channel' => (string) ($send['channel'] ?? 'sms'),
            'expires_in' => (int) ($send['expires_in'] ?? $this->ttlSeconds()),
        ];
    }

    private function buildMessageCentralOtpToken(
        string $purpose,
        string $phoneE164,
        string $verificationId,
        string $flowType,
        string $countryCodeDigits = '',
        string $mobileNumber = '',
        ?string $mcAuthToken = null,
    ): string {
        $payload = [
            'v' => 1,
            'provider' => 'message_central',
            'purpose' => $purpose,
            'phone_e164' => $phoneE164,
            'verification_id' => $verificationId,
            'flow_type' => strtoupper($flowType),
            'country_code_digits' => $countryCodeDigits,
            'mobile_number' => $mobileNumber,
            'exp' => now()->addSeconds($this->ttlSeconds())->timestamp,
        ];

        if ($mcAuthToken !== null && $mcAuthToken !== '') {
            $payload['mc_auth_token'] = $mcAuthToken;
        }

        return Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Lebanon (+961) → UnoSMS. All other countries → Message Central.
     *
     * @return array<string, mixed>
     */
    private function sendSmsOtp(
        string $purpose,
        string $ccDigits,
        string $mobileDigits,
        string $phoneE164,
    ): array {
        if ($this->unoSms->isLebanon($ccDigits) && $this->unoSms->isConfigured()) {
            $code = (string) random_int(100000, 999999);

            $plainMode = (bool) config('otp.unosms.plain_message_mode', false);
            if ($plainMode) {
                $message = trim((string) config('otp.unosms.plain_message_text', 'Test message from Ehkini.'));
                $send = $this->unoSms->sendMessage($phoneE164, $message !== '' ? $message : 'Test message from Ehkini.');
            } else {
                $send = $this->unoSms->sendOtp($phoneE164, $code, $purpose);
            }

            if (! ($send['ok'] ?? false)) {
                return $send;
            }

            $otpToken = $this->buildLocalOtpToken($purpose, $phoneE164, $code);
            if ($otpToken === null) {
                return ['ok' => false, 'error' => 'otp_pepper_missing'];
            }

            return [
                'ok' => true,
                'verification_id' => (string) Str::uuid(),
                'otp_token' => $otpToken,
                'channel' => 'sms',
                'expires_in' => $this->ttlSeconds(),
            ];
        }

        if (! $this->messageCentral->isSmsConfigured()) {
            return [
                'ok' => false,
                'error' => $this->unoSms->isLebanon($ccDigits)
                    ? 'unosms_not_configured'
                    : 'message_central_not_configured',
            ];
        }

        $send = $this->messageCentral->sendOtp($ccDigits, $mobileDigits, 'SMS');

        if (! ($send['ok'] ?? false)) {
            return $send;
        }

        return [
            'ok' => true,
            'verification_id' => (string) ($send['verification_id'] ?? ''),
            'otp_token' => $this->buildMessageCentralOtpToken(
                $purpose,
                $phoneE164,
                (string) $send['verification_id'],
                'SMS',
                $ccDigits,
                $mobileDigits,
                isset($send['auth_token']) ? (string) $send['auth_token'] : null,
            ),
            'channel' => 'sms',
            'expires_in' => $this->ttlSeconds(),
        ];
    }

    private function localOtpPepper(): string
    {
        $pepper = trim((string) config('otp.pepper', ''));
        if ($pepper !== '') {
            return $pepper;
        }

        return trim((string) config('app.key', ''));
    }

    private function buildLocalOtpToken(string $purpose, string $phoneE164, string $code): ?string
    {
        if ($this->localOtpPepper() === '') {
            Log::error('otp.token_build_failed', ['reason' => 'otp_pepper_missing']);

            return null;
        }

        try {
            return $this->whatsAppNode->buildOtpToken($purpose, $phoneE164, $code);
        } catch (\Throwable $e) {
            Log::error('otp.token_build_failed', [
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function formatPhoneE164(string $ccDigits, string $mobileDigits): string
    {
        $ccDigits = ltrim(preg_replace('/\D+/', '', $ccDigits) ?? '', '0');
        $mobileDigits = ltrim(preg_replace('/\D+/', '', $mobileDigits) ?? '', '0');

        return '+' . $ccDigits . $mobileDigits;
    }

    private function decodeToken(string $token): ?array
    {
        try {
            return json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    private function phoneE164(string $countryCode, string $mobileDigits): string
    {
        $ccDigits = $this->messageCentral->countryCodeDigits($countryCode);

        return $this->formatPhoneE164($ccDigits, $mobileDigits);
    }
}
