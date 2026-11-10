# Laravel MiniMailer

[![Latest release](https://img.shields.io/github/v/release/minimailer/laravel-minimailer?style=flat-square)](https://github.com/minimailer/laravel-minimailer/releases)
[![License](https://img.shields.io/github/license/minimailer/laravel-minimailer?style=flat-square&color=yellow)](https://github.com/minimailer/laravel-minimailer/blob/main/LICENSE)
[![Tests](https://img.shields.io/github/actions/workflow/status/minimailer/laravel-minimailer/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/minimailer/laravel-minimailer/actions/workflows/tests.yml)

A first-class Laravel mail transport for [MiniMailer](https://minimailer.app). Send with `MAIL_MAILER=minimailer` — no controller code, just Laravel's `Mail` facade and Mailables. Built on the [`minimailer/minimailer-php`](https://github.com/minimailer/minimailer-php) SDK.

> Pre-release (`0.x`). A thin bridge over the SDK, so it inherits the SDK's pin to the MiniMailer **v1** API contract. The public surface is not covered by SemVer guarantees until `1.0.0`; every change is listed in the [changelog](CHANGELOG.md).

## Requirements

- PHP 8.2+
- Laravel 12 or 13

## Installation

The packages are not on Packagist yet. Add both repositories (the transport depends on the
SDK), then require the transport:

```bash
composer config repositories.minimailer-php vcs https://github.com/minimailer/minimailer-php
composer config repositories.laravel-minimailer vcs https://github.com/minimailer/laravel-minimailer
composer require minimailer/laravel-minimailer:^0.3
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

Display names on the sender and the recipient (`Mail::to('monika@example.com', 'Monika')`, `from('hello@acme.com', 'Acme')`) are sent as the API's `from_name` and `to_name` attributes next to the bare addresses, so they show in the recipient's mail client.

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

### Webhook events

To dispatch verified deliveries as Laravel events, enable the automatic endpoint:

```dotenv
MINIMAILER_WEBHOOK_PATH=minimailer/webhook
MINIMAILER_WEBHOOK_SECRET=whsec_...
```

The package registers a POST route named `minimailer.webhook` in the `api` middleware group, with the signature check. A missing, empty, or whitespace-only path keeps it disabled. The manual route above remains an alternative for apps that want to handle the payload themselves. Customize `minimailer.webhooks.middleware` if needed; a custom `web` group requires your app's CSRF exclusion.

Event classes live in `MiniMailer\Laravel\Webhooks\Events`:

| Payload type | Event |
| --- | --- |
| Every delivery | `WebhookReceived` |
| `email.sent` | `EmailSent` |
| `email.delivered` | `EmailDelivered` |
| `email.delayed` | `EmailDelayed` |
| `email.bounced` | `EmailBounced` |
| `email.complained` | `EmailComplained` |
| `email.opened` | `EmailOpened` |
| `email.clicked` | `EmailClicked` |
| `email.rejected` | `EmailRejected` |
| `email.unsubscribed` | `EmailUnsubscribed` |

`WebhookReceived` fires first, followed by the matching email event. Unknown or other types, such as campaign, domain, or inbound email events, arrive only as `WebhookReceived`; inspect its `type` and `data`.

Every event exposes readonly `webhookId`, `type`, `timestamp` (`DateTimeImmutable`), and `data` properties. Email events also expose `sendId`, `recipient`, `domain`, and their event-specific fields in camelCase, such as `bounceType`, `affectedRecipients`, and `bouncedAt`. Missing or wrongly typed values become `null`, or `[]` for lists; dates become `DateTimeImmutable` or `null`. The original MiniMailer webhook payload's `data` remains available unchanged.

For example, update your app's own address record on a hard bounce:

```php
use App\Models\Contact;
use Illuminate\Support\Facades\Event;
use MiniMailer\Laravel\Webhooks\Events\EmailBounced;

Event::listen(EmailBounced::class, function (EmailBounced $event): void {
    if ($event->bounceType === 'hard' && $event->recipient !== null) {
        Contact::where('email', $event->recipient)->update(['email_is_bad' => true]);
    }
});
```

MiniMailer already suppresses the address on the sending domain: permanently after a hard bounce or a complaint, and for a limited time after a soft bounce. A `MessageTooLarge` bounce is about the message, not the mailbox, so it does not suppress the address: `suppressed` is `false`, and you can retry with a smaller message. The listener checks `bounceType` rather than `suppressed`, so it updates the app's own records only on a hard bounce.

Deliveries are deduplicated on `webhook-id` for 3 days in the cache. The cache store must persist between requests (`redis`, `database`, `file`, `memcached`; not `array`), and apps running on multiple servers need a store they all share. Set `MINIMAILER_WEBHOOK_DEDUPE_TTL` (seconds) and `MINIMAILER_WEBHOOK_CACHE_STORE` to customize this. Successful deliveries return `204`, duplicates return `200`, malformed envelopes return `400`, and a delivery the cache could not record returns `503` so MiniMailer retries it. Invalid envelope timestamps fall back to the signed header time, then the current time.

The deduplication key is stored before listeners run. A listener that throws loses that delivery after the first attempt: the first request fails and the retry is acknowledged without dispatching again. MiniMailer also counts a response slower than 15 seconds as failed. Do slow or fallible work in a `ShouldQueue` listener on an asynchronous queue connection (not `sync`) with a worker running and `$tries` set, so the queue retries the work instead of the webhook. All events support PHP serialization for queued listeners.

## API reference

The v1 contract is published as an OpenAPI document: <https://api.minimailer.app/openapi.yaml>. For anything beyond sending — domains, webhooks, audiences, campaigns, inbound mail, GDPR erasure — use the [SDK](https://github.com/minimailer/minimailer-php) directly.

## Contributing and security

- [Contributing guide](.github/CONTRIBUTING.md) — setup, tests, and the no-network rule for the test suite.
- [Security policy](.github/SECURITY.md) — report vulnerabilities privately, never in a public issue.
- [Changelog](CHANGELOG.md)

## License

[MIT](LICENSE).
