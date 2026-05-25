<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API locales
    |--------------------------------------------------------------------------
    |
    | Default: English. Mobile/UI strings: add rows in DB (`languages`, `translation_values`).
    | APP_LOCALES is optional for built-in lang/{code}/api.php API errors; any active
    | `languages.code` is accepted automatically (e.g. fr without changing this file).
    |
    */
    'default' => env('APP_LOCALE', 'en'),

    'fallback' => 'en',

    'supported' => array_values(array_unique(array_filter(array_map(
        static fn (string $code) => strtolower(trim($code)),
        explode(',', env('APP_LOCALES', 'en,ar'))
    )))) ?: ['en'],

];
