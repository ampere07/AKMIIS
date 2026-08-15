<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    | The origins allowed to call this API.
    |
    | These were the ATSS domains, copied across when this project was forked.
    | Since CORS is matched on the exact origin string, a request from
    | apply.akmiis.com matched nothing and was refused by the browser — in every
    | browser, not only an in-app one.
    */
    'allowed_origins' => [
        'https://apply.akmiis.com',
        'https://backend1.akmiis.com',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    /*
    | How long a browser may reuse a preflight result.
    |
    | At 0 every cross-origin POST paid for a second round trip to
    | backend1.akmiis.com before the real request could start. On a phone inside
    | an in-app browser that is the difference between a form that submits and
    | one that appears to hang.
    */
    'max_age' => 86400,

    'supports_credentials' => true,

];
