<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FcmEnvJson extends Command
{
    protected $signature = 'fcm:env-json {path : Path to Firebase service account .json file}';

    protected $description = 'Print FCM_PROJECT_ID and FCM_CREDENTIALS_JSON lines for .env / Easypanel';

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

        $minified = json_encode($creds, JSON_UNESCAPED_SLASHES);
        $projectId = (string) $creds['project_id'];

        $this->newLine();
        $this->line('# Paste into .env or Easypanel Environment:');
        $this->newLine();
        $this->line('FCM_PROJECT_ID='.$projectId);
        $this->line("FCM_CREDENTIALS_JSON='".$minified."'");
        $this->newLine();
        $this->comment('Remove FCM_CREDENTIALS_BASE64 if you switch to JSON.');
        $this->comment('Then: php artisan config:clear && php artisan fcm:check');

        return self::SUCCESS;
    }
}
