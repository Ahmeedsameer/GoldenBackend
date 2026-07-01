<?php

return [
    /*
    | VAPID keys for the Web Push protocol (no Firebase).
    | Generate with:  php artisan webpush:vapid
    | then copy the values into your .env file.
    */
    'vapid' => [
        'subject'     => env('VAPID_SUBJECT', 'mailto:admin@example.com'),
        'public_key'  => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],
];
