<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmTokenService
{
    public function getAccessToken(): ?string
    {
        return Cache::remember('fcm_access_token', now()->addMinutes(50), function () {

            $credentialsFile = config('services.fcm.credentials_file');

            if (empty($credentialsFile) || !file_exists($credentialsFile)) {
                Log::error('FCM: Credentials file not found', ['path' => $credentialsFile]);
                return null;
            }

            $creds = json_decode(file_get_contents($credentialsFile), true);

            if (!is_array($creds) || empty($creds['client_email']) || empty($creds['private_key'])) {
                Log::error('FCM: Invalid service account JSON.');
                return null;
            }

            $tokenUri = $creds['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $now = time();

            $header = ['alg' => 'RS256', 'typ' => 'JWT'];
            $claims = [
                'iss'   => $creds['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud'   => $tokenUri,
                'exp'   => $now + 3600,
                'iat'   => $now,
            ];

            $base64UrlHeader = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
            $base64UrlClaims = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
            $data = $base64UrlHeader . '.' . $base64UrlClaims;

            $signature = '';
            $ok = openssl_sign($data, $signature, $creds['private_key'], 'SHA256');

            if (!$ok) {
                Log::error('FCM: Failed to sign JWT with private key (openssl_sign failed).');
                return null;
            }

            $base64UrlSignature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
            $jwt = $data . '.' . $base64UrlSignature;

            $response = Http::asForm()->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]);

            if ($response->failed()) {
                Log::error('FCM: Token request failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            $body = $response->json();

            if (empty($body['access_token'])) {
                Log::error('FCM: Token response missing access_token', ['body' => $body]);
                return null;
            }

            return $body['access_token'];
        });
    }
}
