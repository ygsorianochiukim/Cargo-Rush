<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| CargoUI runs on its own origin in development, so the API has to say so
| explicitly — and `supports_credentials` has to be true or the browser will
| drop the Sanctum session cookie on every request (DESIGN.md section 7.4).
|
| cargoApp is unaffected on a handset: a native fetch is not a browser
| request, so CORS never applies, and it authenticates with a bearer token
| rather than a cookie. Under `expo start --web` it IS a browser page, so
| its dev-server origin has to be named here too.
|
*/

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    // A wildcard is not an option here: a credentialed request requires the
    // origin to be named.
    'allowed_origins' => array_values(array_filter([
        env('FRONTEND_URL', 'http://localhost:4200'),
        'http://127.0.0.1:4200',

        // cargoApp under `expo start --web`. Expo's dev server serves the
        // bundle on 8081, so that is the page's origin. Without this a
        // blocked preflight reaches the app as a failed fetch with no
        // status, which it can only report as "cannot reach the server".
        env('EXPO_WEB_URL', 'http://localhost:8081'),
        'http://127.0.0.1:8081',
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
