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

        $accessToken = $this->tokenService->getAccessToken();
        if (empty($accessToken)) {
            Log::error('FCM sendToToken: access token empty');
            return ['ok' => false, 'error' => 'Access token empty'];
        }

        $projectId = config('services.fcm.project_id');
        if (empty($projectId)) {
            Log::error('FCM sendToToken: FCM_PROJECT_ID missing');
            return ['ok' => false, 'error' => 'FCM_PROJECT_ID missing'];
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
            Log::error('FCM sendToToken failed', [
                'status' => $response->status(),
                'body'   => $json ?: $response->body(),
            ]);

            return [
                'ok' => false,
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
