<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database connection name
    |--------------------------------------------------------------------------
    |
    | Name of the Laravel database connection registered for the Manticore driver.
    | Models reach it via the HasManticore trait (Model::manticore()) or by setting
    | this as their $connection (see ManticoreModel). The package auto-fills
    | config('database.connections.<this name>') from the settings below, so you
    | usually don't need to touch config/database.php.
    |
    */

    'connection' => env('MANTICORE_CONNECTION', 'manticore'),

    /*
    |--------------------------------------------------------------------------
    | Connection settings (MySQL protocol — default port 9306)
    |--------------------------------------------------------------------------
    */

    'host'     => env('MANTICORE_HOST', '127.0.0.1'),
    'port'     => env('MANTICORE_PORT', 9306),
    'username' => env('MANTICORE_USERNAME', null),
    'password' => env('MANTICORE_PASSWORD', null),

    /*
    |--------------------------------------------------------------------------
    | Default index engine for migrations
    |--------------------------------------------------------------------------
    |
    | Applied to `Schema::create()` when a blueprint does not set its own engine.
    | Manticore supports 'columnar' and 'rowwise' (null = Manticore default).
    |
    */

    'engine' => env('MANTICORE_ENGINE', null),
];
