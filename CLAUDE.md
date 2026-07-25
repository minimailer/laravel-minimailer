# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this package is

`minimailer/laravel-minimailer` — a thin Laravel bridge that registers a Symfony Mailer transport so apps can send with `MAIL_MAILER=minimailer`. It contains no HTTP client of its own: every request goes through the `minimailer/minimailer-php` SDK. Private pre-release, pinned to the MiniMailer **v1** API contract.

Two files carry the whole package: `src/MinimailerServiceProvider.php` (wiring) and `src/MinimailerTransport.php` (the v1 payload mapping).

## Commands

```bash
composer test                                   # pest, full suite
composer lint                                   # pint --test (check only, never mutates)
vendor/bin/pint                                 # apply style fixes locally
vendor/bin/pest --filter="stamps the message id"  # single test by name
vendor/bin/pest tests/Unit/MinimailerTransportTest.php
```

Two workflows, both on push-to-`main` and pull request:

- `tests.yml` — `pest` across a 24-job matrix (ubuntu/windows/macos × PHP 8.2–8.5 × `prefer-lowest`/`prefer-stable`). Any change must hold on PHP 8.2 *and* on the lowest resolvable Laravel 11 dependency set.
- `quality.yml` — Pint in check mode via `aglipanci/laravel-pint-action` with `testMode: true`. It **fails** on violations and never commits fixes back; fix locally with `vendor/bin/pint` and push. The action bundles its own PHP and Pint, so the job runs no `composer install` and therefore needs neither the private SDK checkout nor `MINIMAILER_SDK_TOKEN`.

Run `composer lint` and `composer test` locally before pushing — that is the literal pair CI gates on.

## GitHub Actions must be SHA-pinned

**Every `uses:` in `.github/workflows/` is pinned to a full-length 40-character commit SHA, with the human-readable version as a trailing comment.** No tags (`@v4`), no branches (`@main`), no short SHAs — a tag is mutable and can be repointed at attacker-controlled code after review.

```yaml
uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # v4.4.0
```

When adding or bumping an action, resolve the tag to its commit SHA first and paste the full 40 chars:

```bash
gh api repos/actions/checkout/commits/v4.4.0 --jq '.sha'
```

(`repos/O/R/commits/<tag>` resolves both lightweight and annotated tags straight to the commit; `git/ref/tags/<tag>` returns a tag object that needs dereferencing for annotated tags.)

Audit the whole workflow directory with `grep -rn 'uses:' .github/workflows/` — every line must show 40 hex chars after the `@`. Dependabot honours this format and bumps the SHA plus the comment together, so keep the comment accurate.

## SDK coupling — the thing that breaks first

`minimailer/minimailer-php` is required at `@dev` and resolved through a **path repository** named `minimailer-php` pointing at `../minimailer-php`, symlinked into `vendor/`. Consequences:

- The sibling checkout must exist at `~/dev/code/minimailer-php`. Editing the SDK there changes this package's behaviour immediately — no `composer update` needed.
- The repository *key* `minimailer-php` in `composer.json` is load-bearing: CI checks the SDK out into the workspace and repoints it with `composer config repositories.minimailer-php ...` (needs the `MINIMAILER_SDK_TOKEN` secret). Renaming the key breaks CI silently-ish.
- `composer.lock` is gitignored (library convention), so local and CI resolutions differ by design.
- When both packages go public on Packagist: drop the `repositories` block, pin a real version constraint, and delete the two SDK-checkout/config steps in the workflow.

Transport calls exactly one SDK surface: `$minimailer->emails()->create($payload)` (inherited from `MiniMailer\Resources\Resource`) returning `MiniMailer\Response` (`status()`, `body()`, `json($key)`). The SDK's `Client` already throws typed `MiniMailer\Exceptions\*` for non-2xx — the transport's `catch (Throwable)` wraps those into `TransportException`.

## Architecture and invariants

**Container-free transport.** `MinimailerTransport` takes `(MiniMailerContract, string $domainId, ?LoggerInterface)` via constructor — no facades, no `config()`, no container. This is deliberate: tests construct it directly with a Guzzle `MockHandler`, so `orchestra/testbench` is a dev dep that no test currently needs and `tests/Pest.php` has no `uses()`. Keep it that way; resolve config in the provider, not the transport.

**Provider wiring** (`register()` → singletons `ClientContract` and `MiniMailerContract`; `boot()` → `Mail::extend('minimailer', ...)` + config publish under tag `minimailer-config`). `domain_id` resolves per-mailer first (`config/mail.php` mailer array), falling back to `minimailer.domain_id`.

**v1 payload shape** — `data.type = outbound-emails`, attributes run through `array_filter` (nulls/empties dropped), `relationships.domain` = `{type: domains, id: $domainId}`. Only HTTP **201** counts as success; anything else becomes a `TransportException`.

**Never log the payload.** The error path logs only redacted metadata (recipient/cc/bcc/attachment counts, subject *length*, status, error code). Recipient addresses, subject text, body, and base64 attachments are PII and must not reach logs. Preserve this if you touch the catch block.

**Header pass-through** excludes `STANDARD_HEADERS` (from/to/cc/bcc/reply-to/sender/subject/content-type) — those are derived from the JSON:API attributes, so re-sending them would duplicate.

**Primary recipient** is resolved from the *envelope* recipients minus CC and BCC (`getRecipient()`), not from `$email->getTo()`.

**Attachments** are base64-encoded; `content_id` is emitted only for `inline` disposition (falls back to the filename when the part has no content id).

## Testing pattern

`tests/Unit/MinimailerTransportTest.php` defines a local `transportReplaying(array $responses, ?array &$history)` helper: a Guzzle `MockHandler` replays canned responses and `Middleware::history` captures the outgoing request, which tests decode to assert the exact JSON:API body. Assert against the *request payload*, not against SDK internals.

Gotcha the existing tests document: `AbstractTransport::send()` clones the message, so the `X-Minimailer-Message-Id` stamp lands on the returned `SentMessage`'s message, not on the `$email` you passed in.

## Style

Laravel Pint preset `laravel`. `declare(strict_types=1)` everywhere; classes are `final`. No `#[Override]` attributes — they were removed for the PHP 8.2 floor.
