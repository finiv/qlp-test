<?php

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        'pgsql' => [
            'driver'         => 'pgsql',
            'host'           => env('DB_HOST', 'db'),
            'port'           => env('DB_PORT', '5432'),
            'database'       => env('DB_DATABASE', 'reply_center'),
            'username'       => env('DB_USERNAME', 'app'),
            'password'       => env('DB_PASSWORD', 'secret'),
            'charset'        => 'utf8',
            'prefix'         => '',
            'search_path'    => 'public',
            'sslmode'        => 'prefer',
        ],

    ],

    'migrations' => 'migrations',

];
