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
    | Webhook secret
    |--------------------------------------------------------------------------
    |
    | The `whsec_...` secret MiniMailer showed once when the webhook endpoint
    | was created. It verifies the Standard Webhooks `v1` HMAC signature on
    | incoming webhook requests (see the VerifyMinimailerWebhook middleware).
    | During a secret rotation list both secrets, comma-separated, until the
    | 24-hour dual-signing window has passed.
    |
    */

    'webhook_secret' => env('MINIMAILER_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Webhook timestamp tolerance
    |--------------------------------------------------------------------------
    |
    | How far (in seconds) a webhook's `webhook-timestamp` may differ from the
    | server clock before the request is rejected as a replay.
    |
    */

    'webhook_tolerance' => (int) env('MINIMAILER_WEBHOOK_TOLERANCE', 300),

];
