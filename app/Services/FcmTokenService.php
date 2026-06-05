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
     * FCM HTTP v1 project id: env first, then service account JSON project_id.
     */
    public function resolveProjectId(): ?string
    {
        $fromEnv = trim((string) (config('services.fcm.project_id') ?: env('FCM_PROJECT_ID') ?: ''));
        if ($fromEnv !== '' && ! $this->isPlaceholderProjectId($fromEnv)) {
            return $fromEnv;
        }

        $creds = $this->resolveCredentials();
        $fromCreds = is_array($creds) ? trim((string) ($creds['project_id'] ?? '')) : '';

        return $fromCreds !== '' ? $fromCreds : null;
    }

    private function isPlaceholderProjectId(string $value): bool
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, [
            'your_firebase_project_id',
            'your-project-id',
            'your_project_id',
            'changeme',
            'xxx',
        ], true);
    }

    /**
     * Return a human-readable reason when FCM is not configured, or null if OK.
     */
    public function configurationError(): ?string
    {
        $projectId = $this->resolveProjectId();
        if ($projectId === null || $projectId === '') {
            return 'FCM_PROJECT_ID is not set in Easypanel Environment (use taaruf-f15c3)';
        }

        $creds = $this->resolveCredentials();

        if ($creds === null) {
            $diag = $this->credentialsDiagnostics();

            if ($diag['base64_set'] || $diag['json_set']) {
                return 'FCM credentials env is set but invalid. '.$diag['summary'];
            }

            return 'FCM credentials not found. '.$diag['summary'];
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
        $json = $this->envValue('FCM_CREDENTIALS_JSON', 'services.fcm.credentials_json');
        if ($json !== '') {
            $creds = $this->parseJsonCredentials($json);
            if (is_array($creds)) {
                $this->persistCredentialsFile($creds);

                return $creds;
            }
        }

        $base64 = $this->normalizeBase64(
            $this->envValue('FCM_CREDENTIALS_BASE64', 'services.fcm.credentials_base64')
        );
        if ($base64 !== '') {
            $decoded = base64_decode($base64, true);
            if ($decoded === false) {
                $decoded = base64_decode($base64, false);
            }
            if ($decoded !== false && $decoded !== '') {
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

    private function envValue(string $envKey, string $configKey): string
    {
        $fromConfig = config($configKey);
        if (filled($fromConfig)) {
            return trim((string) $fromConfig);
        }

        $fromEnv = env($envKey);

        return filled($fromEnv) ? trim((string) $fromEnv) : '';
    }

    private function normalizeBase64(string $value): string
    {
        // Easypanel / copy-paste often adds spaces or line breaks.
        return preg_replace('/\s+/', '', $value) ?? '';
    }

    /**
     * Parse Firebase JSON from .env (single-quoted, double-quoted, or raw minified).
     *
     * @return array<string, mixed>|null
     */
    private function parseJsonCredentials(string $raw): ?array
    {
        $candidates = [$raw];

        $trimmed = trim($raw);
        if (
            (str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'"))
            || (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"'))
        ) {
            $candidates[] = substr($trimmed, 1, -1);
        }

        $candidates[] = stripslashes($trimmed);

        // Easypanel: pretty-printed JSON pasted on multiple lines (if env captured it).
        $unquoted = $trimmed;
        if (
            (str_starts_with($unquoted, "'") && str_ends_with($unquoted, "'"))
            || (str_starts_with($unquoted, '"') && str_ends_with($unquoted, '"'))
        ) {
            $unquoted = substr($unquoted, 1, -1);
        }
        $candidates[] = preg_replace('/\s+/', '', $unquoted) ?? $unquoted;

        foreach (array_unique(array_filter($candidates)) as $candidate) {
            $creds = json_decode($candidate, true);
            if (is_array($creds)) {
                return $this->normalizeCredentialArray($creds);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $creds
     * @return array<string, mixed>
     */
    private function normalizeCredentialArray(array $creds): array
    {
        if (isset($creds['private_key']) && is_string($creds['private_key'])) {
            $creds['private_key'] = str_replace(['\\n', '\n'], "\n", $creds['private_key']);
        }

        return $creds;
    }

    /**
     * @return array{summary: string, base64_set: bool, json_set: bool, base64_len: int, project_id: string}
     */
    private function credentialsDiagnostics(): array
    {
        $base64Raw = $this->envValue('FCM_CREDENTIALS_BASE64', 'services.fcm.credentials_base64');
        $jsonRaw = $this->envValue('FCM_CREDENTIALS_JSON', 'services.fcm.credentials_json');
        $base64Len = strlen($this->normalizeBase64($base64Raw));
        $projectId = (string) (config('services.fcm.project_id') ?: env('FCM_PROJECT_ID') ?: '');

        $parts = [
            'FCM_PROJECT_ID='.($projectId !== '' ? $projectId : '(not set)'),
            'FCM_CREDENTIALS_BASE64 length='.$base64Len.' (expected ~3168 for taaruf JSON)',
            'FCM_CREDENTIALS_JSON length='.strlen($jsonRaw),
            'files tried: '.implode(', ', $this->credentialFileCandidates()),
        ];

        if ($base64Len > 0 && $base64Len < 500) {
            $parts[] = 'hint: base64 looks too short — paste the full line from fcm-base64-TAARUF-easypanel.txt';
        }

        if ($base64Raw !== '' && str_contains($base64Raw, '<base64')) {
            $parts[] = 'hint: replace placeholder text with real base64, not <base64 of firebase JSON>';
        }

        return [
            'summary' => implode('; ', $parts),
            'base64_set' => $base64Raw !== '',
            'json_set' => $jsonRaw !== '',
            'base64_len' => $base64Len,
            'project_id' => $projectId,
        ];
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
