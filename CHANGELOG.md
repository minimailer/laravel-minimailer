# Changelog

All notable changes to `minimailer/laravel-minimailer` will be documented in this file.

## Unreleased

Pre-release. The package is a thin bridge over `minimailer/minimailer-php` and inherits
its pin to the MiniMailer **v1** API contract; the public surface is not yet covered by
SemVer guarantees until `1.0.0` is tagged.

### Added

- `minimailer` Symfony Mailer transport — send with `MAIL_MAILER=minimailer`.
- `MinimailerServiceProvider` wiring the transport into Laravel's mail manager, with a
  publishable `config/minimailer.php` (`key`, `base_url`, `domain_id`, `webhook_public_keys`).
- `X-Minimailer-Message-Id` header on the sent message, carrying the API's email id.
- Laravel 11, 12 and 13 support on PHP 8.2+.
