<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API locales
    |--------------------------------------------------------------------------
    |
    | Default: English. Add codes to supported + lang/{code}/api.php to scale.
    | Example: APP_LOCALES=en,ar,fr
    |
    */
    'default' => env('APP_LOCALE', 'en'),

    'fallback' => 'en',

    'supported' => array_values(array_unique(array_filter(array_map(
        static fn (string $code) => strtolower(trim($code)),
        explode(',', env('APP_LOCALES', 'en,ar'))
    )))) ?: ['en'],

];
