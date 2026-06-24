<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Message Central VerifyNow — SMS OTP only.
 * WhatsApp OTP uses the separate WhatsApp node, not Message Central.
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
        // This project uses Message Central for SMS OTP only.
        $flowType = 'SMS';

        if (! $this->hasCredentials()) {
            return ['ok' => false, 'error' => 'message_central_not_configured'];
        }

        $cfg = config('otp.message_central');
        $customerId = $this->normalizeCustomerId((string) $cfg['customer_id']);
        $otpLength = (int) ($cfg['otp_length'] ?? 6);

        // Attempt 1 — full v3 (customerId + otpLength).
        $v3Full = [
            'countryCode' => $countryCodeDigits,
            'customerId' => $customerId,
            'flowType' => 'SMS',
            'mobileNumber' => $mobileNumber,
            'otpLength' => $otpLength,
        ];
        $v3 = $this->postWithQuery($cfg, '/verification/v3/send', $v3Full);
        $v3Result = $this->parseSendResponse($v3['status'], $v3['json'], $v3['body'], $v3['query'] ?? $v3Full);
        if ($v3Result['ok'] ?? false) {
            return $this->successSendResult($v3Result);
        }
        $this->logSendFailure('v3-full', $countryCodeDigits, $mobileNumber, $v3Result);

        // Attempt 2 — official cURL example (countryCode, flowType, mobileNumber only).
        $v3Minimal = [
            'countryCode' => $countryCodeDigits,
            'flowType' => 'SMS',
            'mobileNumber' => $mobileNumber,
        ];
        $v3b = $this->postWithQuery($cfg, '/verification/v3/send', $v3Minimal);
        $v3bResult = $this->parseSendResponse($v3b['status'], $v3b['json'], $v3b['body'], $v3b['query'] ?? $v3Minimal);
        if ($v3bResult['ok'] ?? false) {
            return $this->successSendResult($v3bResult);
        }
        $this->logSendFailure('v3-minimal', $countryCodeDigits, $mobileNumber, $v3bResult);

        // Attempt 3 — v2 fallback.
        $v2Query = [
            'countryCode' => $countryCodeDigits,
            'customerId' => $customerId,
            'mobileNumber' => $mobileNumber,
            'flowType' => 'SMS',
        ];
        $v2 = $this->postWithQuery($cfg, '/verification/v2/verification/send', $v2Query);
        $v2Result = $this->parseSendResponse($v2['status'], $v2['json'], $v2['body'], $v2['query'] ?? $v2Query);
        if ($v2Result['ok'] ?? false) {
            return $this->successSendResult($v2Result);
        }
        $this->logSendFailure('v2', $countryCodeDigits, $mobileNumber, $v2Result);

        $failure = [
            'ok' => false,
            'error' => 'message_central_send_failed',
            'error_summary' => $this->summarizeFailure($v3Result, $v3bResult, $v2Result),
            'attempts' => [
                ['version' => 'v3-full', 'result' => $v3Result],
                ['version' => 'v3-minimal', 'result' => $v3bResult],
                ['version' => 'v2', 'result' => $v2Result],
            ],
        ];

        Log::warning('message_central.sms_send_failed', [
            'country_code' => $countryCodeDigits,
            'phone_last4' => substr($mobileNumber, -4),
            'summary' => $failure['error_summary'],
        ]);

        return $failure;
    }

    public function validateOtp(string $verificationId, string $code, string $flowType = 'SMS'): array
    {
        if (! $this->hasCredentials()) {
            return ['ok' => false, 'error' => 'message_central_not_configured'];
        }

        $cfg = config('otp.message_central');
        $flowType = 'SMS';

        // POST /verification/v3/validateOtp/ with flowType=SMS
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
     * @return array{status:int, json:mixed, body:string, query:array<string, mixed>, url:string}
     */
    private function postWithQuery(array $cfg, string $path, array $query): array
    {
        $baseUrl = rtrim((string) $cfg['base_url'], '/');
        $url = $baseUrl . $path;

        try {
            $response = Http::withHeaders($this->headers($cfg))
                ->withQueryParameters($query)
                ->timeout(25)
                ->post($url);
        } catch (\Throwable $e) {
            Log::error('message_central.http_exception', [
                'url' => $url,
                'query' => $query,
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => 0,
                'json' => null,
                'body' => $e->getMessage(),
                'query' => $query,
                'url' => $url,
            ];
        }

        return [
            'status' => $response->status(),
            'json' => $response->json(),
            'body' => (string) $response->body(),
            'query' => $query,
            'url' => $url,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function parseSendResponse(int $httpStatus, mixed $json, string $rawBody, array $query = []): array
    {
        if (! is_array($json)) {
            return [
                'ok' => false,
                'error' => 'message_central_send_failed',
                'http' => $httpStatus,
                'body' => $rawBody,
                'request' => $query,
            ];
        }

        $responseCode = (int) (data_get($json, 'responseCode') ?? data_get($json, 'data.responseCode') ?? 0);
        $message = strtoupper((string) (data_get($json, 'message') ?? ''));
        $mcError = (string) (data_get($json, 'data.errorMessage') ?? data_get($json, 'errorMessage') ?? '');

        if ($httpStatus >= 400 || ($responseCode !== 0 && $responseCode !== 200 && $message !== 'SUCCESS')) {
            return [
                'ok' => false,
                'error' => 'message_central_send_failed',
                'http' => $httpStatus,
                'response_code' => $responseCode,
                'mc_message' => $mcError !== '' ? $mcError : (data_get($json, 'message') ?? null),
                'body' => $json,
                'request' => $query,
            ];
        }

        $verificationId = data_get($json, 'data.verificationId')
            ?? data_get($json, 'data.verficationId');

        if (! $verificationId) {
            return [
                'ok' => false,
                'error' => 'message_central_no_verification_id',
                'body' => $json,
                'request' => $query,
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

    private function successSendResult(array $result): array
    {
        $result['flow_type'] = 'SMS';

        return $result;
    }

    private function logSendFailure(string $version, string $cc, string $phone, array $result): void
    {
        Log::info('message_central.sms_attempt_failed', [
            'version' => $version,
            'country_code' => $cc,
            'phone_last4' => substr($phone, -4),
            'error' => $result['error'] ?? null,
            'http' => $result['http'] ?? null,
            'response_code' => $result['response_code'] ?? null,
            'mc_message' => $result['mc_message'] ?? null,
        ]);
    }

    private function summarizeFailure(array ...$results): string
    {
        foreach ($results as $result) {
            if (! empty($result['mc_message'])) {
                return (string) $result['mc_message'];
            }
            $code = (int) ($result['response_code'] ?? 0);
            if ($code === 501) {
                return 'Invalid Message Central customer ID (501).';
            }
            if ($code === 511) {
                return 'Invalid country code for SMS (511).';
            }
            if ($code === 800) {
                return 'Message Central rate limit reached (800).';
            }
            if (($result['error'] ?? '') === 'message_central_connection_failed') {
                return 'Could not connect to Message Central: '.($result['detail'] ?? 'unknown');
            }
        }

        $first = $results[0] ?? [];
        $http = (int) ($first['http'] ?? 0);
        if ($http > 0) {
            return 'Message Central HTTP '.$http;
        }

        return 'Message Central rejected the SMS request. Run: php artisan otp:test-sms +961 YOURPHONE';
    }
}
