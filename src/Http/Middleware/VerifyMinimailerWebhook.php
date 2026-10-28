<?php

declare(strict_types=1);

namespace MiniMailer\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Verifies that an incoming request was signed by MiniMailer, following the
 * Standard Webhooks specification (https://standardwebhooks.com): the
 * `webhook-id`, `webhook-timestamp` and `webhook-signature` headers, a
 * `v1,<base64>` HMAC-SHA256 over `id.timestamp.raw_body` keyed with the
 * endpoint secret (`whsec_` + base64 bytes), a replay window on the timestamp,
 * and constant-time comparison.
 *
 * Several secrets may be configured (comma-separated in `minimailer.webhook_secret`)
 * so a rotation never drops a delivery: MiniMailer signs with both the current and
 * the previous secret for 24 hours after a rotation, and this verifier accepts a
 * request when any configured secret matches any presented `v1` signature.
 *
 * Responses: 400 when a header is missing or malformed or the timestamp is outside
 * the tolerance window, 401 when no signature matches. Container-free: build it with
 * the secrets directly in tests; the service provider binds it from config.
 */
final class VerifyMinimailerWebhook
{
    public const SECRET_PREFIX = 'whsec_';

    public const SCHEME = 'v1';

    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Smallest HMAC key accepted, in decoded bytes: the length of the secret in
     * the Standard Webhooks specification's own test vector (MiniMailer issues
     * 32). A shorter value is a configuration mistake, never a weaker secret.
     */
    public const MIN_KEY_BYTES = 24;

    /**
     * @var list<string> raw HMAC keys (the base64-decoded part after `whsec_`)
     */
    private readonly array $keys;

    /**
     * @param  list<string>  $secrets  one or more `whsec_…` secrets
     * @param  (Closure(): int)|null  $clock  current Unix time, overridable in tests
     */
    public function __construct(
        array $secrets,
        private readonly int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        private readonly ?Closure $clock = null,
    ) {
        $keys = [];

        foreach ($secrets as $secret) {
            $secret = trim($secret);

            if (! str_starts_with($secret, self::SECRET_PREFIX)) {
                continue;
            }

            $decoded = base64_decode(substr($secret, strlen(self::SECRET_PREFIX)), true);

            if ($decoded === false || strlen($decoded) < self::MIN_KEY_BYTES) {
                continue;
            }

            $keys[] = $decoded;
        }

        $this->keys = $keys;
    }

    /**
     * Split the `minimailer.webhook_secret` config value (one secret, or several
     * comma-separated during a rotation) into the list the constructor takes.
     *
     * @return list<string>
     */
    public static function secretsFromConfig(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $secret): bool => $secret !== ''));
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->headers->get('webhook-id');
        $timestamp = $request->headers->get('webhook-timestamp');
        $signatureHeader = $request->headers->get('webhook-signature');

        if ($id === null || $id === '' || $timestamp === null || $timestamp === '' || $signatureHeader === null || $signatureHeader === '') {
            throw new BadRequestHttpException('Missing Standard Webhooks headers.');
        }

        if (preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            throw new BadRequestHttpException('Malformed webhook timestamp.');
        }

        $now = $this->clock instanceof Closure ? ($this->clock)() : time();

        if (abs($now - (int) $timestamp) > $this->toleranceSeconds) {
            throw new BadRequestHttpException('Webhook timestamp outside the tolerance window.');
        }

        if ($this->keys === []) {
            throw new UnauthorizedHttpException(self::SCHEME, 'No webhook secret configured.');
        }

        $signedContent = $id.'.'.$timestamp.'.'.$request->getContent();

        foreach ($this->keys as $key) {
            $expected = base64_encode(hash_hmac('sha256', $signedContent, $key, true));

            foreach ($this->signatures($signatureHeader) as $signature) {
                if (hash_equals($expected, $signature)) {
                    return $next($request);
                }
            }
        }

        throw new UnauthorizedHttpException(self::SCHEME, 'Webhook signature verification failed.');
    }

    /**
     * The `v1` signatures in a space-delimited `webhook-signature` header; other
     * schemes (such as `v1a`) are ignored, never rejected.
     *
     * @return list<string>
     */
    private function signatures(string $header): array
    {
        $signatures = [];

        foreach (preg_split('/\s+/', trim($header)) ?: [] as $part) {
            [$scheme, $signature] = array_pad(explode(',', $part, 2), 2, null);

            if ($scheme === self::SCHEME && is_string($signature) && $signature !== '') {
                $signatures[] = $signature;
            }
        }

        return $signatures;
    }
}
