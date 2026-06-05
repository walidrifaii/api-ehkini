<?php

namespace App\Console\Commands;

use App\Services\FcmTokenService;
use Illuminate\Console\Command;

class CheckFcmConfig extends Command
{
    protected $signature = 'fcm:check';

    protected $description = 'Verify FCM env/config and attempt OAuth token fetch';

    public function handle(FcmTokenService $fcmTokenService): int
    {
        $projectId = $fcmTokenService->resolveProjectId();
        $base64Len = strlen(preg_replace('/\s+/', '', (string) (config('services.fcm.credentials_base64') ?: env('FCM_CREDENTIALS_BASE64') ?: '')) ?? '');

        $this->line('FCM_PROJECT_ID (resolved): '.($projectId ?: '(not set)'));
        $this->line('FCM_CREDENTIALS_BASE64 length: '.$base64Len.' (expected ~3168)');

        $error = $fcmTokenService->configurationError();
        if ($error !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $token = $fcmTokenService->getAccessToken();
        if ($token === null) {
            $this->error($fcmTokenService->lastError() ?? 'Failed to obtain FCM access token');

            return self::FAILURE;
        }

        $this->info('FCM OK — access token obtained (length '.strlen($token).')');

        return self::SUCCESS;
    }
}
