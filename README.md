# Laravel MiniMailer

A first-class Laravel mail transport for [MiniMailer](https://minimailer.app). Send with `MAIL_MAILER=minimailer` — no controller code, just Laravel's `Mail` facade and Mailables. Built on the [`minimailer/minimailer-php`](https://github.com/minimailer/minimailer-php) SDK.

> Private / pre-release. Pinned to the MiniMailer **v1** API contract.

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

The API key must carry the `emails:send` scope.

## Usage

Send exactly as you would with any Laravel mailer:

```php
use Illuminate\Support\Facades\Mail;

Mail::to('customer@example.com')->send(new OrderShipped($order));
```

The transport supports HTML and plain-text bodies, CC/BCC, reply-to, custom headers, and attachments (including inline `cid:` images). The MiniMailer message id is stamped onto the sent message as the `X-Minimailer-Message-Id` header.

## License

MIT.
