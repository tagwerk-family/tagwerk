<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | Server-wide credentials only. What belongs to a household (logins,
    | tokens, addresses) lives encrypted in its provider account.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),

        /*
         * The URL Google sends its notifications to. Must be publicly
         * reachable and have a valid CA certificate — Google rejects
         * self-signed certificates.
         */
        'webhook_url' => env('GOOGLE_WEBHOOK_URL'),

        /*
         * Travels along as the channel token and comes back in every
         * notification. Without this check anyone could trigger our sync jobs.
         */
        'webhook_token' => env('GOOGLE_WEBHOOK_TOKEN'),
    ],

    /*
     * Web push to the phone PWA. `php artisan fahoba:vapid-keys` generates
     * the keys once.
     *
     * Changing the public key invalidates all existing subscriptions — the
     * browsers would have to subscribe again. That is why the keys belong in
     * the backup and not in the repository.
     */
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:office@copex.io'),
    ],

];
