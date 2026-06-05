<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmTokenService
{
    /** Last configuration or token error (for API responses / logs). */
    protected ?string $lastError = null;

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Return a human-readable reason when FCM is not configured, or null if OK.
     */
    public function configurationError(): ?string
    {
        $credentialsFile = (string) config('services.fcm.credentials_file');

        if ($credentialsFile === '') {
            return 'FCM credentials path not set (FCM_CREDENTIALS_FILE)';
        }

        if (! is_file($credentialsFile)) {
            return 'FCM credentials file not found at: '.$credentialsFile;
        }

        $creds = json_decode((string) file_get_contents($credentialsFile), true);

        if (! is_array($creds) || empty($creds['client_email']) || empty($creds['private_key'])) {
            return 'FCM credentials file is invalid (missing client_email or private_key)';
        }

        if (empty(config('services.fcm.project_id'))) {
            return 'FCM_PROJECT_ID is not set in .env';
        }

        return null;
    }

    public function getAccessToken(): ?string
    {
        $this->lastError = null;

        $configError = $this->configurationError();
        if ($configError !== null) {
            $this->lastError = $configError;
            Log::error('FCM: '.$configError);

            return null;
        }

        $cached = Cache::get('fcm_access_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->fetchAccessToken();

        if ($token !== null) {
            Cache::put('fcm_access_token', $token, now()->addMinutes(50));
        }

        return $token;
    }

    private function fetchAccessToken(): ?string
    {
        $credentialsFile = config('services.fcm.credentials_file');

        $creds = json_decode((string) file_get_contents($credentialsFile), true);

        if (! is_array($creds) || empty($creds['client_email']) || empty($creds['private_key'])) {
            $this->lastError = 'FCM credentials file is invalid (missing client_email or private_key)';
            Log::error('FCM: '.$this->lastError);

            return null;
        }

        $tokenUri = $creds['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now = time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $tokenUri,
            'exp' => $now + 3600,
            'iat' => $now,
        ];

        $base64UrlHeader = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
        $base64UrlClaims = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
        $data = $base64UrlHeader.'.'.$base64UrlClaims;

        $signature = '';
        $ok = openssl_sign($data, $signature, $creds['private_key'], 'SHA256');

        if (! $ok) {
            $this->lastError = 'FCM failed to sign JWT (openssl_sign failed)';
            Log::error('FCM: '.$this->lastError);

            return null;
        }

        $base64UrlSignature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        $jwt = $data.'.'.$base64UrlSignature;

        $response = Http::asForm()->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if ($response->failed()) {
            $this->lastError = 'FCM OAuth token request failed (HTTP '.$response->status().')';
            Log::error('FCM: Token request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $body = $response->json();

        if (empty($body['access_token'])) {
            $this->lastError = 'FCM OAuth response missing access_token';
            Log::error('FCM: Token response missing access_token', ['body' => $body]);

            return null;
        }

        return $body['access_token'];
    }
}
