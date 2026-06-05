<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FcmEnvBase64 extends Command
{
    protected $signature = 'fcm:env-base64 {path : Path to Firebase service account .json file}';

    protected $description = 'Print FCM_PROJECT_ID and FCM_CREDENTIALS_BASE64 for Easypanel';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error('File not found: '.$path);

            return self::FAILURE;
        }

        $json = file_get_contents($path);
        $creds = json_decode((string) $json, true);

        if (! is_array($creds) || empty($creds['project_id'])) {
            $this->error('Invalid Firebase service account JSON');

            return self::FAILURE;
        }

        $base64 = base64_encode((string) $json);

        $this->newLine();
        $this->line('# Easypanel Environment — copy these 2 variables ONLY:');
        $this->newLine();
        $this->line('FCM_PROJECT_ID='.(string) $creds['project_id']);
        $this->line('FCM_CREDENTIALS_BASE64='.$base64);
        $this->newLine();
        $this->comment('Delete FCM_CREDENTIALS_JSON if present.');
        $this->comment('Then: php artisan config:clear && php artisan fcm:check');

        return self::SUCCESS;
    }
}
