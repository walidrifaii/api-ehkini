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

            Log::error('FCM sendToToken failed', [
                'status' => $response->status(),
                'project_id' => $projectId,
                'body'   => $json ?: $response->body(),
            ]);

            return [
                'ok' => false,
                'error' => is_string($apiMessage) && $apiMessage !== ''
                    ? $apiMessage
                    : 'FCM request failed (HTTP '.$response->status().')',
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
}
