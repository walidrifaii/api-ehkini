<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;

class OtpDeliveryService
{
    public function __construct(
        private readonly WhatsAppNodeCampaignOtpService $whatsAppNode,
        private readonly MessageCentralOtpService $messageCentral,
    ) {}

    public function ttlSeconds(): int
    {
        return $this->whatsAppNode->ttlSeconds();
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
        $phoneE164 = $this->phoneE164($countryCode, $mobileNumber);
        $ccDigits = $this->messageCentral->countryCodeDigits($countryCode);
        $attempts = [];

        $channels = $this->resolveSendChannels($channel);
        if ($channels === []) {
            return [
                'ok' => false,
                'error' => 'otp_channels_not_configured',
                'hint' => 'Enable WhatsApp node and/or Message Central SMS in .env',
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

            $mobileDigits = $this->messageCentral->mobileNumberDigits($ccDigits, $mobileNumber);
            $send = $this->messageCentral->sendOtp($ccDigits, $mobileDigits, 'SMS');
            $attempts[] = ['channel' => $resolvedChannel, 'result' => $send];

            if ($send['ok'] ?? false) {
                return [
                    'ok' => true,
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
                ];
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
        $payload = $this->decodeToken($token);
        if ($payload === null) {
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
            // App "SMS" button → Message Central VerifyNow only.
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

        // auto without explicit channel: try WhatsApp node, then Message Central SMS.
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

    private function decodeToken(string $token): ?array
    {
        try {
            return json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    private function phoneE164(string $countryCode, string $mobileNumber): string
    {
        $cc = preg_replace('/\s+/', '', trim($countryCode));
        if ($cc !== '' && $cc[0] !== '+') {
            $cc = '+' . $cc;
        }

        $mobile = ltrim(preg_replace('/[\s\-]+/', '', trim($mobileNumber)) ?? '', '0');

        return $cc . $mobile;
    }
}
