<?php

use Illuminate\Support\Facades\Route;

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Here you may configure your CORS settings for your application.
    | Adjust these to your specific production domain.
    |
    */

    'paths' => ['api/*', 'sanctum/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://masahati-five.vercel.app',
        'https://front-end-kwba.onrender.com',
        'http://localhost:3000',
        'http://localhost:5173',
        'http://localhost:8080',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,

];
