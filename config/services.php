<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        // Option A (Easypanel): paste JSON or base64 in env — no file upload needed.
        'credentials_json' => env('FCM_CREDENTIALS_JSON'),
        'credentials_base64' => env('FCM_CREDENTIALS_BASE64'),
        // Option B: path to service account JSON on disk (Docker default below).
        'credentials_file' => env('FCM_CREDENTIALS_FILE')
            ?: env('FIREBASE_CREDENTIALS')
            ?: env('GOOGLE_APPLICATION_CREDENTIALS')
            ?: storage_path('app/firebase-service-account.json'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

];
