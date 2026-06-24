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
     * @return array{ok:bool, otp_token?:string, channel?:string, error?:string, attempts?:array<int,array<string,mixed>>}
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

        foreach ($this->resolveSendChannels($channel) as $resolvedChannel) {
            if ($resolvedChannel === 'whatsapp_node') {
                $code = random_int(100000, 999999);
                $send = $this->whatsAppNode->sendOtpViaNodeCampaign($phoneE164, $code);
                $attempts[] = ['channel' => $resolvedChannel, 'result' => $send];

                if ($send['ok'] ?? false) {
                    return [
                        'ok' => true,
                        'otp_token' => $this->buildLocalOtpToken($purpose, $phoneE164, $code),
                        'channel' => $resolvedChannel,
                    ];
                }

                continue;
            }

            $flowType = $resolvedChannel === 'whatsapp_mc' ? 'WHATSAPP' : 'SMS';
            $send = $this->messageCentral->sendOtp($ccDigits, $mobileNumber, $flowType);
            $attempts[] = ['channel' => $resolvedChannel, 'result' => $send];

            if ($send['ok'] ?? false) {
                return [
                    'ok' => true,
                    'otp_token' => $this->buildMessageCentralOtpToken(
                        $purpose,
                        $phoneE164,
                        (string) $send['verification_id'],
                        $flowType,
                    ),
                    'channel' => $resolvedChannel,
                ];
            }
        }

        return [
            'ok' => false,
            'error' => 'no_otp_channel_available',
            'attempts' => $attempts,
        ];
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
            return $this->messageCentral->validateOtp(
                (string) ($payload['verification_id'] ?? ''),
                $code,
                (string) ($payload['flow_type'] ?? 'SMS'),
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
            'whatsapp' => ['whatsapp_node', 'whatsapp_mc'],
            'whatsapp_node' => ['whatsapp_node'],
            'sms' => ['sms'],
            'whatsapp_mc' => ['whatsapp_mc'],
        ];

        if ($requested !== '' && isset($map[$requested])) {
            return $this->filterAvailableChannels($map[$requested]);
        }

        if ($preferred !== 'auto' && isset($map[$preferred])) {
            return $this->filterAvailableChannels($map[$preferred]);
        }

        return $this->filterAvailableChannels(['whatsapp_node', 'sms', 'whatsapp_mc']);
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
            if ($channel === 'whatsapp_mc' && $this->isMessageCentralWhatsAppAvailable()) {
                $available[] = $channel;
            }
        }

        return $available;
    }

    private function buildLocalOtpToken(string $purpose, string $phoneE164, int $code): string
    {
        return $this->whatsAppNode->buildOtpToken($purpose, $phoneE164, $code);
    }

    private function buildMessageCentralOtpToken(
        string $purpose,
        string $phoneE164,
        string $verificationId,
        string $flowType,
    ): string {
        $payload = [
            'v' => 1,
            'provider' => 'message_central',
            'purpose' => $purpose,
            'phone_e164' => $phoneE164,
            'verification_id' => $verificationId,
            'flow_type' => strtoupper($flowType),
            'exp' => now()->addSeconds($this->ttlSeconds())->timestamp,
        ];

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
