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
        env('MEDIA_URL', env('IMAGEKIT_URL_ENDPOINT', env('APP_URL', 'http://localhost').'/storage')),
        '/'
    ),

    /*
    |--------------------------------------------------------------------------
    | Where new files are written
    |--------------------------------------------------------------------------
    |
    | imagekit — ImageKit.io CDN (upload + delivery)
    | ftp      — remote server via FTP
    | public   — storage/app/public on this API server
    |
    */
    'disk' => env('MEDIA_DISK', 'public'),

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

    'imagekit' => [
        'public_key' => env('IMAGEKIT_PUBLIC_KEY'),
        'private_key' => env('IMAGEKIT_PRIVATE_KEY'),
        'url_endpoint' => rtrim(env('IMAGEKIT_URL_ENDPOINT', ''), '/'),
        // Optional folder prefix in ImageKit Media Library (e.g. ehkini)
        'folder_prefix' => trim(env('IMAGEKIT_FOLDER_PREFIX', 'ehkini'), '/'),
    ],

];
