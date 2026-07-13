<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Frontend origins allowed to call the API with credentials (cookies).
    'allowed_origins' => array_filter([
        env('FRONTEND_URL', 'http://localhost:3000'),
        'http://localhost:3000',
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Without this the browser hides the header from JS, and a downloaded
    // invoice lands on disk named after its UUID instead of its number.
    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    // Required so the browser sends/receives the HttpOnly refresh cookie.
    'supports_credentials' => true,

];
