<?php

return [
    'name'   => env('APP_NAME', 'ReplyCenter'),
    'env'    => env('APP_ENV', 'production'),
    'debug'  => (bool) env('APP_DEBUG', false),
    'key'    => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',

    'timezone' => 'UTC',
    'locale'   => 'en',
    'faker_locale' => 'en_CA',

    'providers' => [],
];
