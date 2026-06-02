<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public media base URL (read in browser / mobile app)
    |--------------------------------------------------------------------------
    |
    | imagekit — uses IMAGEKIT_URL_ENDPOINT (recommended)
    | ftp      — e.g. https://amcserver.com/app/taaruf/storage/app/public
    | public   — APP_URL/storage on this server
    |
    */
    'url' => rtrim(
        env('MEDIA_URL', env('UPLOAD_PUBLIC_BASE', env('IMAGEKIT_URL_ENDPOINT', env('APP_URL', 'http://localhost').'/storage'))),
        '/'
    ),

    /*
    |--------------------------------------------------------------------------
    | Where new files are written
    |--------------------------------------------------------------------------
    |
    | remote   — remote upload.php endpoint (upload + delivery) [default]
    | imagekit — ImageKit.io CDN (upload + delivery)
    | ftp      — remote server via FTP
    | public   — storage/app/public on this API server
    |
    */
    'disk' => env('MEDIA_DISK', 'remote'),

    /*
    |--------------------------------------------------------------------------
    | Full URLs in database
    |--------------------------------------------------------------------------
    |
    | When true, new uploads save https://... in DB (not only profiles/xxx.jpg).
    | Backfill old rows: php artisan media:backfill-full-urls
    |
    */
    'store_full_url_in_db' => filter_var(env('MEDIA_STORE_FULL_URL_IN_DB', true), FILTER_VALIDATE_BOOLEAN),

    /*
    | Old amcserver base for migrating relative paths already on disk.
    */
    'legacy_base_url' => rtrim(
        env('MEDIA_LEGACY_BASE_URL', 'https://amcserver.com/app/taaruf/storage/app/public'),
        '/'
    ),

    /*
    |--------------------------------------------------------------------------
    | Remote upload endpoint (upload.php)
    |--------------------------------------------------------------------------
    |
    | endpoint    — POST target that accepts multipart "file" and returns JSON
    |               {"success":true,"path":"image/<name>.jpg",...}
    | token       — bearer token sent as Authorization header and "token" field
    | public_base — base URL where uploaded files are publicly served. The
    |               endpoint returns a relative path (e.g. image/x.jpg), so set
    |               this to the folder that serves them. Falls back to media.url.
    |
    */
    'remote' => [
        'endpoint' => env('UPLOAD_ENDPOINT', 'https://st79068.ispot.cc/upload.php'),
        'token' => env('UPLOAD_API_TOKEN', 'YourSuperSecretToken2026!@#'),
        'public_base' => rtrim(env('UPLOAD_PUBLIC_BASE', 'https://st79068.ispot.cc'), '/'),
    ],

    'imagekit' => [
        'public_key' => env('IMAGEKIT_PUBLIC_KEY'),
        'private_key' => env('IMAGEKIT_PRIVATE_KEY'),
        'url_endpoint' => rtrim(env('IMAGEKIT_URL_ENDPOINT', ''), '/'),
        // Optional folder prefix in ImageKit Media Library (e.g. ehkini)
        'folder_prefix' => trim(env('IMAGEKIT_FOLDER_PREFIX', 'ehkini'), '/'),
    ],

];
