<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Message Central VerifyNow API (v3).
 *
 * @see https://www.messagecentral.com/product/verify-now/api
 */
class MessageCentralOtpService
{
    public function isSmsConfigured(): bool
    {
        $cfg = config('otp.message_central');

        return ($cfg['enabled'] ?? false)
            && ($cfg['sms_enabled'] ?? false)
            && $this->hasCredentials();
    }

    public function isWhatsAppConfigured(): bool
    {
        $cfg = config('otp.message_central');

        return ($cfg['enabled'] ?? false)
            && ($cfg['whatsapp_enabled'] ?? false)
            && $this->hasCredentials();
    }

    public function sendOtp(string $countryCodeDigits, string $mobileNumber, string $flowType = 'SMS'): array
    {
        if (! $this->hasCredentials()) {
            return ['ok' => false, 'error' => 'message_central_not_configured'];
        }

        $cfg = config('otp.message_central');
        $customerId = $this->normalizeCustomerId((string) $cfg['customer_id']);
        $flowType = strtoupper($flowType);
        $otpLength = (int) ($cfg['otp_length'] ?? 6);

        // Official v3 cURL: countryCode, flowType, mobileNumber (+ otpLength, type=OTP).
        $v3Query = [
            'countryCode' => $countryCodeDigits,
            'flowType' => $flowType,
            'mobileNumber' => $mobileNumber,
            'otpLength' => $otpLength,
            'type' => 'OTP',
        ];

        $v3 = $this->postWithQuery($cfg, '/verification/v3/send', $v3Query);
        $v3Result = $this->parseSendResponse($v3['status'], $v3['json'], $v3['body']);
        if ($v3Result['ok'] ?? false) {
            $v3Result['flow_type'] = $flowType;

            return $v3Result;
        }

        // Some accounts also require customerId on send.
        $v3WithCustomer = $this->postWithQuery($cfg, '/verification/v3/send', [
            ...$v3Query,
            'customerId' => $customerId,
        ]);
        $v3CustomerResult = $this->parseSendResponse(
            $v3WithCustomer['status'],
            $v3WithCustomer['json'],
            $v3WithCustomer['body'],
        );
        if ($v3CustomerResult['ok'] ?? false) {
            $v3CustomerResult['flow_type'] = $flowType;

            return $v3CustomerResult;
        }

        // Fallback v2.
        $v2 = $this->postWithQuery($cfg, '/verification/v2/verification/send', [
            'countryCode' => $countryCodeDigits,
            'customerId' => $customerId,
            'mobileNumber' => $mobileNumber,
            'flowType' => $flowType,
        ]);
        $v2Result = $this->parseSendResponse($v2['status'], $v2['json'], $v2['body']);
        if ($v2Result['ok'] ?? false) {
            $v2Result['flow_type'] = $flowType;

            return $v2Result;
        }

        return [
            'ok' => false,
            'error' => 'message_central_send_failed',
            'attempts' => [
                ['version' => 'v3', 'result' => $v3Result],
                ['version' => 'v3+customerId', 'result' => $v3CustomerResult],
                ['version' => 'v2', 'result' => $v2Result],
            ],
        ];
    }

    public function validateOtp(string $verificationId, string $code, string $flowType = 'SMS'): array
    {
        if (! $this->hasCredentials()) {
            return ['ok' => false, 'error' => 'message_central_not_configured'];
        }

        $cfg = config('otp.message_central');
        $flowType = strtoupper($flowType);

        // Official v3: POST /verification/v3/validateOtp/ ?verificationId=&code=&flowType=
        $query = [
            'verificationId' => $verificationId,
            'code' => $code,
            'flowType' => $flowType,
        ];

        $v3 = $this->postWithQuery($cfg, '/verification/v3/validateOtp/', $query);
        $v3Result = $this->parseValidateResponse($v3['status'], $v3['json'], $v3['body']);
        if ($v3Result['ok'] ?? false) {
            return $v3Result;
        }

        $v2 = $this->postWithQuery($cfg, '/verification/v2/verification/validateOtp', $query);
        $v2Result = $this->parseValidateResponse($v2['status'], $v2['json'], $v2['body']);
        if ($v2Result['ok'] ?? false) {
            return $v2Result;
        }

        if (($v3Result['error'] ?? '') === 'invalid_code' || ($v2Result['error'] ?? '') === 'invalid_code') {
            return ['ok' => false, 'error' => 'invalid_code', 'body' => $v3Result['body'] ?? $v2Result['body'] ?? null];
        }

        return $v3Result;
    }

    public function countryCodeDigits(string $countryCode): string
    {
        return ltrim(preg_replace('/\D+/', '', $countryCode) ?? '', '0');
    }

    /**
     * @return array{status:int, json:mixed, body:string}
     */
    private function postWithQuery(array $cfg, string $path, array $query): array
    {
        try {
            $response = Http::withHeaders($this->headers($cfg))
                ->withQueryParameters($query)
                ->timeout(25)
                ->post(rtrim((string) $cfg['base_url'], '/') . $path);
        } catch (\Throwable $e) {
            return [
                'status' => 0,
                'json' => null,
                'body' => $e->getMessage(),
            ];
        }

        return [
            'status' => $response->status(),
            'json' => $response->json(),
            'body' => (string) $response->body(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseSendResponse(int $httpStatus, mixed $json, string $rawBody): array
    {
        if (! is_array($json)) {
            return [
                'ok' => false,
                'error' => 'message_central_send_failed',
                'http' => $httpStatus,
                'body' => $rawBody,
            ];
        }

        $responseCode = (int) (data_get($json, 'responseCode') ?? data_get($json, 'data.responseCode') ?? 0);
        $message = strtoupper((string) (data_get($json, 'message') ?? ''));

        if ($httpStatus >= 400 || ($responseCode !== 0 && $responseCode !== 200 && $message !== 'SUCCESS')) {
            return [
                'ok' => false,
                'error' => 'message_central_send_failed',
                'http' => $httpStatus,
                'body' => $json,
            ];
        }

        $verificationId = data_get($json, 'data.verificationId')
            ?? data_get($json, 'data.verficationId');

        if (! $verificationId) {
            return [
                'ok' => false,
                'error' => 'message_central_no_verification_id',
                'body' => $json,
            ];
        }

        return [
            'ok' => true,
            'verification_id' => (string) $verificationId,
            'body' => $json,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseValidateResponse(int $httpStatus, mixed $json, string $rawBody): array
    {
        if (! is_array($json)) {
            return [
                'ok' => false,
                'error' => 'message_central_validate_failed',
                'http' => $httpStatus,
                'body' => $rawBody,
            ];
        }

        $responseCode = (int) (data_get($json, 'responseCode') ?? data_get($json, 'data.responseCode') ?? 0);
        $message = strtoupper((string) (data_get($json, 'message') ?? ''));
        $status = strtoupper((string) data_get($json, 'data.verificationStatus', ''));

        if (in_array($responseCode, [702, 705, 700], true)) {
            return ['ok' => false, 'error' => 'invalid_code', 'body' => $json];
        }

        if ($httpStatus >= 400 || ($responseCode !== 0 && $responseCode !== 200 && $message !== 'SUCCESS')) {
            return [
                'ok' => false,
                'error' => 'message_central_validate_failed',
                'http' => $httpStatus,
                'body' => $json,
            ];
        }

        if ($status !== '' && $status !== 'VERIFICATION_COMPLETED') {
            return ['ok' => false, 'error' => 'invalid_code', 'body' => $json];
        }

        return ['ok' => true, 'body' => $json];
    }

    /**
     * @return array<string, string>
     */
    private function headers(array $cfg): array
    {
        return [
            'authToken' => (string) ($cfg['auth_token'] ?? ''),
            'accept' => '*/*',
        ];
    }

    private function hasCredentials(): bool
    {
        $cfg = config('otp.message_central');

        return ($cfg['customer_id'] ?? '') !== '' && ($cfg['auth_token'] ?? '') !== '';
    }

    private function normalizeCustomerId(string $customerId): string
    {
        $customerId = trim($customerId);
        if ($customerId === '') {
            return $customerId;
        }

        return str_starts_with($customerId, 'C-') ? $customerId : ('C-' . $customerId);
    }
}
