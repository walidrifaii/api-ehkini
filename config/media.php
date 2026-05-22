<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public media base URL (read in browser / mobile app)
    |--------------------------------------------------------------------------
    |
    | All profile, post, story, and media upload API responses use this base.
    | Example (amcserver): https://amcserver.com/app/taaruf/storage/app/public
    |
    */
    'url' => rtrim(
        env('MEDIA_URL', env('APP_URL', 'http://localhost') . '/storage'),
        '/'
    ),

    /*
    |--------------------------------------------------------------------------
    | Where new files are written
    |--------------------------------------------------------------------------
    |
    | public — storage/app/public on this API server (Easypanel volume)
    | ftp    — remote server via FTP (same folders as amcserver Laravel app)
    |
    */
    'disk' => env('MEDIA_DISK', 'public'),

];
