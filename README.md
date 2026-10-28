# Laravel MiniMailer

[![Latest Version on Packagist](https://img.shields.io/packagist/v/minimailer/laravel-minimailer.svg?style=flat-square)](https://packagist.org/packages/minimailer/laravel-minimailer)
[![Total Downloads](https://img.shields.io/packagist/dt/minimailer/laravel-minimailer.svg?style=flat-square)](https://packagist.org/packages/minimailer/laravel-minimailer)
[![License](https://img.shields.io/packagist/l/minimailer/laravel-minimailer.svg?style=flat-square&color=yellow)](https://github.com/minimailer/laravel-minimailer/blob/main/LICENSE)
[![Tests](https://img.shields.io/github/actions/workflow/status/minimailer/laravel-minimailer/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/minimailer/laravel-minimailer/actions/workflows/tests.yml)

A first-class Laravel mail transport for [MiniMailer](https://minimailer.app). Send with `MAIL_MAILER=minimailer` — no controller code, just Laravel's `Mail` facade and Mailables. Built on the [`minimailer/minimailer-php`](https://github.com/minimailer/minimailer-php) SDK.

> Pre-release (`0.x`). A thin bridge over the SDK, so it inherits the SDK's pin to the MiniMailer **v1** API contract. The public surface is not covered by SemVer guarantees until `1.0.0`; every change is listed in the [changelog](CHANGELOG.md).

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13

## Installation

```bash
composer require minimailer/laravel-minimailer
```

The service provider is auto-discovered. Optionally publish the config:

```bash
php artisan vendor:publish --tag=minimailer-config
```

## Configuration

Add the mailer to `config/mail.php`:

```php
'mailers' => [
    // ...
    'minimailer' => [
        'transport' => 'minimailer',
        'domain_id' => env('MINIMAILER_TRANSACTIONAL_DOMAIN_ID'),
    ],
],
```

Set your environment:

```dotenv
MAIL_MAILER=minimailer
MINIMAILER_API_KEY=your-api-key
MINIMAILER_TRANSACTIONAL_DOMAIN_ID=dom_...
```

The API key must carry the `emails:send` scope. `MINIMAILER_BASE_URL` overrides the API base URL (default `https://api.minimailer.app/`) and only matters for staging or self-hosted environments.

## Usage

Send exactly as you would with any Laravel mailer:

```php
use Illuminate\Support\Facades\Mail;

Mail::to('customer@example.com')->send(new OrderShipped($order));
```

The transport supports HTML and plain-text bodies, CC/BCC, reply-to, custom headers, and attachments (including inline `cid:` images). The MiniMailer message id is stamped onto the sent message as the `X-Minimailer-Message-Id` header.

### HTTP behaviour

The transport uses the SDK's default HTTP client, so a send gives up after 10 s connecting and 30 s per request, never follows redirects, and identifies itself with a `minimailer-php/<version>` User-Agent. When a send fails, the transport logs only redacted metadata (recipient and attachment counts, subject length, HTTP status) — never addresses, subject, body, or the API key.

### Idempotent sends

A queued mailable can be retried after the API accepted the message but the response was lost. To make such a retry a no-op instead of a second delivery, set a stable, unique key on the message and the transport sends it as the API's `idempotency_key`:

```php
use MiniMailer\Laravel\MinimailerTransport;

public function headers(): Headers
{
    return new Headers(text: [MinimailerTransport::IDEMPOTENCY_KEY_HEADER => 'order-confirmed:'.$this->order->id]);
}
```

The header itself is never forwarded. Use an id of the thing being announced (an order, a notification), never a timestamp or a random value.

## Webhooks

MiniMailer signs every webhook delivery following the [Standard Webhooks](https://standardwebhooks.com) specification. Put the `VerifyMinimailerWebhook` middleware on your endpoint and set the secret the dashboard showed when the webhook was created:

```php
use MiniMailer\Laravel\Http\Middleware\VerifyMinimailerWebhook;

Route::post('minimailer/webhook', MinimailerWebhookController::class)
    ->middleware(VerifyMinimailerWebhook::class)
    ->withoutMiddleware(VerifyCsrfToken::class);
```

```dotenv
MINIMAILER_WEBHOOK_SECRET=whsec_...
```

The middleware checks the `webhook-id`, `webhook-timestamp` and `webhook-signature` headers, rejects timestamps outside a 5-minute window (`MINIMAILER_WEBHOOK_TOLERANCE`), and compares the `v1` HMAC-SHA256 signature in constant time. A request that fails gets a `400` (missing or malformed headers, stale timestamp) or a `401` (no matching signature). During a secret rotation list both secrets, comma-separated, for the 24 hours MiniMailer signs with both. Your controller then reads the envelope — `type`, `timestamp`, `data` — from the JSON body; keep it idempotent on `webhook-id`, because deliveries are retried.

## API reference

The v1 contract is published as an OpenAPI document: <https://api.minimailer.app/openapi.yaml>. For anything beyond sending — domains, webhooks, audiences, campaigns, inbound mail, GDPR erasure — use the [SDK](https://github.com/minimailer/minimailer-php) directly.

## Contributing and security

- [Contributing guide](.github/CONTRIBUTING.md) — setup, tests, and the no-network rule for the test suite.
- [Security policy](.github/SECURITY.md) — report vulnerabilities privately, never in a public issue.
- [Changelog](CHANGELOG.md)

## License

[MIT](LICENSE).
