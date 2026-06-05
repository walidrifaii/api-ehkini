<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    public function __construct(
        protected FcmTokenService $tokenService
    ) {}

    /**
     * Send to ONE token (HTTP v1)
     */
    public function sendToToken(string $token, string $title, string $body, array $data = []): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'error' => 'Empty token'];
        }

        $configError = $this->tokenService->configurationError();
        if ($configError !== null) {
            Log::error('FCM sendToToken: '.$configError);

            return ['ok' => false, 'error' => $configError];
        }

        $accessToken = $this->tokenService->getAccessToken();
        if (empty($accessToken)) {
            $error = $this->tokenService->lastError() ?: 'Access token empty';
            Log::error('FCM sendToToken: '.$error);

            return ['ok' => false, 'error' => $error];
        }

        $projectId = $this->tokenService->resolveProjectId();
        if (empty($projectId)) {
            Log::error('FCM sendToToken: FCM_PROJECT_ID missing');

            return ['ok' => false, 'error' => 'FCM_PROJECT_ID missing (set to taaruf-f15c3 in Easypanel)'];
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        // Ensure data values are strings
        $data = collect($data)->mapWithKeys(function ($v, $k) {
            if (is_array($v) || is_object($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            return [$k => (string) $v];
        })->toArray();

        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body'  => $body,
                ],
                'data' => $data,
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type'  => 'application/json',
        ])->post($url, $payload);

        if ($response->failed()) {
            $json = $response->json();
            $apiMessage = is_array($json)
                ? ($json['error']['message'] ?? null)
                : null;
            $errorCode = is_array($json)
                ? ($json['error']['details'][0]['errorCode'] ?? $json['error']['status'] ?? null)
                : null;

            Log::error('FCM sendToToken failed', [
                'status' => $response->status(),
                'project_id' => $projectId,
                'error_code' => $errorCode,
                'body'   => $json ?: $response->body(),
            ]);

            $errorText = is_string($apiMessage) && $apiMessage !== ''
                ? $apiMessage
                : 'FCM request failed (HTTP '.$response->status().')';

            if ($this->isInvalidDeviceTokenError($apiMessage, $errorCode)) {
                $errorText = 'FCM device token invalid or expired — receiver must log in again to refresh fcm_token';
            } elseif ($this->isFcmIamDenied($apiMessage, $errorCode)) {
                $errorText = 'FCM IAM denied: add role Firebase Admin (roles/firebase.admin) or Firebase Admin SDK Administrator Service Agent to '
                    .'firebase-adminsdk-fbsvc@taaruf-f15c3.iam.gserviceaccount.com — NOT Firebase Cloud Messaging Admin (that role lacks cloudmessaging.messages.create). '
                    .'Also enable Firebase Cloud Messaging API in Google Cloud.';
            }

            return [
                'ok' => false,
                'error' => $errorText,
                'error_code' => $errorCode,
                'status' => $response->status(),
                'body' => $json ?: $response->body(),
            ];
        }

        return [
            'ok' => true,
            'status' => $response->status(),
            'body' => $response->json(),
        ];
    }

    private function isInvalidDeviceTokenError(mixed $message, mixed $errorCode): bool
    {
        $haystack = strtolower((string) $message.' '.(string) $errorCode);

        return str_contains($haystack, 'registration token')
            || str_contains($haystack, 'not a valid')
            || str_contains($haystack, 'unregistered')
            || $errorCode === 'UNREGISTERED'
            || $errorCode === 'INVALID_ARGUMENT';
    }

    private function isFcmIamDenied(mixed $message, mixed $errorCode): bool
    {
        $haystack = strtolower((string) $message.' '.(string) $errorCode);

        return str_contains($haystack, 'cloudmessaging.messages.create')
            || str_contains($haystack, 'iam_permission_denied');
    }
}
