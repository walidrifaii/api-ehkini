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
        if (empty(config('services.fcm.project_id'))) {
            return 'FCM_PROJECT_ID is not set in .env';
        }

        $creds = $this->resolveCredentials();

        if ($creds === null) {
            if (filled(config('services.fcm.credentials_json')) || filled(config('services.fcm.credentials_base64'))) {
                return 'FCM credentials JSON is set but invalid (check FCM_CREDENTIALS_JSON or FCM_CREDENTIALS_BASE64)';
            }

            return 'FCM credentials not found. Easypanel Environment: set FCM_PROJECT_ID=taaruf-f15c3 and FCM_CREDENTIALS_BASE64=<base64 of firebase JSON>. '
                .'Tried paths: '.implode(', ', $this->credentialFileCandidates());
        }

        if (empty($creds['client_email']) || empty($creds['private_key'])) {
            return 'FCM credentials are invalid (missing client_email or private_key)';
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

    /**
     * Load Firebase service account from env JSON/base64 or from disk.
     *
     * @return array<string, mixed>|null
     */
    private function resolveCredentials(): ?array
    {
        $json = config('services.fcm.credentials_json');
        if (filled($json)) {
            $creds = json_decode((string) $json, true);
            if (is_array($creds)) {
                $this->persistCredentialsFile($creds);

                return $creds;
            }
        }

        $base64 = config('services.fcm.credentials_base64');
        if (filled($base64)) {
            $decoded = base64_decode((string) $base64, true);
            if ($decoded !== false) {
                $creds = json_decode($decoded, true);
                if (is_array($creds)) {
                    $this->persistCredentialsFile($creds);

                    return $creds;
                }
            }
        }

        $file = $this->findCredentialsFile();
        if ($file !== null) {
            $creds = json_decode((string) file_get_contents($file), true);

            return is_array($creds) ? $creds : null;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function credentialFileCandidates(): array
    {
        $configured = trim((string) config('services.fcm.credentials_file'));

        $candidates = array_filter([
            $configured !== '' ? $configured : null,
            storage_path('app/firebase-service-account.json'),
            storage_path('app/firebase/serviceAccount.json'),
        ]);

        return array_values(array_unique($candidates));
    }

    private function findCredentialsFile(): ?string
    {
        foreach ($this->credentialFileCandidates() as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Cache credentials JSON on disk (Easypanel persistent storage) when provided via env.
     *
     * @param array<string, mixed> $creds
     */
    private function persistCredentialsFile(array $creds): void
    {
        $path = storage_path('app/firebase-service-account.json');
        if (is_file($path)) {
            return;
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($path, json_encode($creds, JSON_UNESCAPED_SLASHES));
    }

    private function fetchAccessToken(): ?string
    {
        $creds = $this->resolveCredentials();

        if (! is_array($creds) || empty($creds['client_email']) || empty($creds['private_key'])) {
            $this->lastError = 'FCM credentials are invalid (missing client_email or private_key)';
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
