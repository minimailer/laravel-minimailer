<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API key
    |--------------------------------------------------------------------------
    |
    | The bearer token used to authenticate against the MiniMailer API. The
    | token must carry the `emails:send` scope to send transactional email.
    |
    */

    'key' => env('MINIMAILER_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    |
    | The MiniMailer v1 API base URL. Override only for staging or self-hosted
    | environments.
    |
    */

    'base_url' => env('MINIMAILER_BASE_URL', 'https://api.minimailer.app/'),

    /*
    |--------------------------------------------------------------------------
    | Transactional sending domain
    |--------------------------------------------------------------------------
    |
    | The public id of the verified sending domain used for transactional mail
    | (e.g. `dom_...`). May be overridden per mailer in config/mail.php.
    |
    */

    'domain_id' => env('MINIMAILER_TRANSACTIONAL_DOMAIN_ID'),

    /*
    |--------------------------------------------------------------------------
    | Webhook public keys
    |--------------------------------------------------------------------------
    |
    | Public key(s) used to verify inbound webhook signatures from MiniMailer.
    |
    */

    'webhook_public_keys' => env('MINIMAILER_WEBHOOK_PUBLIC_KEYS'),

];
