# Changelog

All notable changes to `minimailer/laravel-minimailer` will be documented in this file.

## Unreleased

Pre-release. The package is a thin bridge over `minimailer/minimailer-php` and inherits
its pin to the MiniMailer **v1** API contract; the public surface is not yet covered by
SemVer guarantees until `1.0.0` is tagged.

### Added

- Sender display name: the transport sends the `From` address's name as the API's `from_name`
  attribute and the bare address as `from`, so `Mail::from('hello@acme.com', 'Acme')` shows
  "Acme" in the recipient's client. Requires MiniMailer v1 with `from_name` support.
- `VerifyMinimailerWebhook` middleware: Standard Webhooks `v1` HMAC-SHA256 verification of
  incoming MiniMailer webhooks (timestamp tolerance, constant-time comparison, comma-separated
  secrets for rotation), configured by `minimailer.webhook_secret` and `webhook_tolerance`.
- Opt-in idempotent sends: a `X-Minimailer-Idempotency-Key` header on the message is sent as the
  API's `idempotency_key` attribute (and stripped from the forwarded headers), so a queue retry
  after a lost response cannot deliver twice.
- `minimailer` Symfony Mailer transport — send with `MAIL_MAILER=minimailer`.
- `MinimailerServiceProvider` wiring the transport into Laravel's mail manager, with a
  publishable `config/minimailer.php` (`key`, `base_url`, `domain_id`, `webhook_secret`,
  `webhook_tolerance`).

### Changed

- `config/minimailer.php`: `webhook_public_keys` replaced by `webhook_secret` — the v1 API signs
  webhooks with a shared `whsec_` secret (Standard Webhooks `v1`), not an Ed25519 public key.
- `X-Minimailer-Message-Id` header on the sent message, carrying the API's email id.
- Laravel 11, 12 and 13 support on PHP 8.2+.
