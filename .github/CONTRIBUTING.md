# Contributing

Contributions are **welcome** and will be fully **credited**.

Please read and understand this guide before creating an issue or pull request.

## Etiquette

Maintainers give their free time to build and maintain this code and make it freely
available in the hope it will be of use. Please be considerate when raising issues or
presenting pull requests. It is the maintainer's duty to ensure submissions are of
sufficient quality to benefit the project; respect that decision.

## Before filing an issue

- Attempt to reproduce the problem, to confirm it wasn't a coincidental incident.
- Check that your feature suggestion isn't already present.
- Check the pull requests tab for a fix or feature already in progress.
- If the problem is in the HTTP layer (wrong URL, unexpected status, exception type),
  it most likely belongs to the underlying SDK, `minimailer/minimailer-php` — file it there.

Security issues do **not** belong in the issue tracker — see [SECURITY.md](SECURITY.md).

## Before submitting a pull request

Run both gates locally; CI runs exactly these and will fail the run on either:

```bash
composer lint    # pint --test — checks style, does not fix it
composer test    # pest (Orchestra Testbench)
```

If `composer lint` fails, fix it with `vendor/bin/pint` and commit the result. CI never
formats your branch for you.

This package supports **PHP 8.2+** and **Laravel 11 to 13**, so avoid syntax newer than
8.2 and framework APIs newer than 11 — the test matrix covers PHP 8.2 to 8.5 on Linux,
Windows and macOS, against both `prefer-lowest` and `prefer-stable` dependency
resolutions. If you change a dependency constraint, run:

```bash
composer update --prefer-lowest --prefer-dist
composer test
```

The lowest-bound resolution is what catches a constraint widened past what the code
actually supports.

## Requirements

- **Document any change in behaviour** — keep `README.md`, `config/minimailer.php` and any
  other relevant docs current.
- **Consider the release cycle** — this project follows [SemVer v2.0.0](https://semver.org/).
  The public surface (`MinimailerTransport`, `MinimailerServiceProvider`, the published
  config keys and the `X-Minimailer-Message-Id` header) is treated as a contract; changes
  to it are breaking changes.
- **One pull request per feature** — send multiple PRs if you want to do more than one thing.
- **Send coherent history** — make each commit meaningful; squash intermediate work before submitting.
- **Add a test** — new behaviour needs a Pest test. Tests must not make real network
  calls; back the SDK client with a Guzzle `MockHandler` stack, as the existing transport test does.

**Happy coding**!
