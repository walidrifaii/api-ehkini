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

    'imagekit' => [
        'public_key' => env('IMAGEKIT_PUBLIC_KEY'),
        'private_key' => env('IMAGEKIT_PRIVATE_KEY'),
        'url_endpoint' => rtrim(env('IMAGEKIT_URL_ENDPOINT', ''), '/'),
        // Optional folder prefix in ImageKit Media Library (e.g. ehkini)
        'folder_prefix' => trim(env('IMAGEKIT_FOLDER_PREFIX', 'ehkini'), '/'),
    ],

];
