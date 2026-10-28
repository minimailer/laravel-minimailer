<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MiniMailer\Laravel\Http\Middleware\VerifyMinimailerWebhook;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

// The official Standard Webhooks test vector: proves interoperability, not just a
// self-consistent round trip.
const SPEC_SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
const SPEC_MSG_ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';
const SPEC_TIMESTAMP = 1614265330;
const SPEC_PAYLOAD = '{"test": 2432232314}';
const SPEC_SIGNATURE = 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=';

/**
 * @param  array<string, string|null>  $headers
 */
function signedRequest(array $headers = [], string $body = SPEC_PAYLOAD): Request
{
    $request = Request::create('/minimailer/webhook', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);

    foreach (array_merge(['webhook-id' => SPEC_MSG_ID, 'webhook-timestamp' => (string) SPEC_TIMESTAMP, 'webhook-signature' => SPEC_SIGNATURE], $headers) as $name => $value) {
        if ($value === null) {
            $request->headers->remove($name);
        } else {
            $request->headers->set($name, $value);
        }
    }

    return $request;
}

function sign(string $secret, string $msgId, int $timestamp, string $body): string
{
    $key = (string) base64_decode(substr($secret, strlen('whsec_')), true);

    return 'v1,'.base64_encode(hash_hmac('sha256', $msgId.'.'.$timestamp.'.'.$body, $key, true));
}

function verifier(array $secrets = [SPEC_SECRET], int $now = SPEC_TIMESTAMP, int $tolerance = 300): VerifyMinimailerWebhook
{
    return new VerifyMinimailerWebhook($secrets, $tolerance, static fn (): int => $now);
}

function passThrough(): Closure
{
    return static fn (Request $request): Response => new Response('ok', 200);
}

it('accepts the official Standard Webhooks test vector', function (): void {
    $response = verifier()->handle(signedRequest(), passThrough());

    expect($response->getStatusCode())->toBe(200)
        ->and(sign(SPEC_SECRET, SPEC_MSG_ID, SPEC_TIMESTAMP, SPEC_PAYLOAD))->toBe(SPEC_SIGNATURE);
});

it('accepts a request signed with the previous secret during a rotation and ignores other schemes', function (): void {
    $rotated = 'whsec_'.base64_encode(random_bytes(32));
    $signature = sign($rotated, SPEC_MSG_ID, SPEC_TIMESTAMP, SPEC_PAYLOAD);

    $request = signedRequest(['webhook-signature' => 'v1a,bm90LWEtcmVhbC1zaWduYXR1cmU= '.$signature.' '.SPEC_SIGNATURE]);

    expect(verifier([$rotated, 'whsec_'.base64_encode('another-key')])->handle($request, passThrough())->getStatusCode())->toBe(200)
        ->and(verifier([' '.SPEC_SECRET.' '])->handle($request, passThrough())->getStatusCode())->toBe(200);
});

it('rejects a tampered body, a wrong secret, a malformed or undersized secret and no secret', function (): void {
    expect(fn () => verifier()->handle(signedRequest(body: '{"test": 2432232315}'), passThrough()))
        ->toThrow(UnauthorizedHttpException::class, 'signature verification failed');

    expect(fn () => verifier(['whsec_'.base64_encode(random_bytes(32))])->handle(signedRequest(), passThrough()))
        ->toThrow(UnauthorizedHttpException::class);

    // A one-byte "secret" that happens to sign the request is a configuration error, not a key.
    $tiny = 'whsec_'.base64_encode('k');
    expect(fn () => verifier([$tiny])->handle(signedRequest(['webhook-signature' => sign($tiny, SPEC_MSG_ID, SPEC_TIMESTAMP, SPEC_PAYLOAD)]), passThrough()))
        ->toThrow(UnauthorizedHttpException::class, 'No webhook secret configured');

    expect(fn () => verifier(['not-a-secret', 'whsec_%%%'])->handle(signedRequest(), passThrough()))
        ->toThrow(UnauthorizedHttpException::class, 'No webhook secret configured');

    expect(fn () => verifier([])->handle(signedRequest(), passThrough()))
        ->toThrow(UnauthorizedHttpException::class, 'No webhook secret configured');
});

it('rejects a replayed or future timestamp beyond the tolerance, and accepts one inside it', function (): void {
    expect(fn () => verifier(now: SPEC_TIMESTAMP + 301)->handle(signedRequest(), passThrough()))
        ->toThrow(BadRequestHttpException::class, 'tolerance window');

    expect(fn () => verifier(now: SPEC_TIMESTAMP - 301)->handle(signedRequest(), passThrough()))
        ->toThrow(BadRequestHttpException::class, 'tolerance window');

    expect(verifier(now: SPEC_TIMESTAMP + 300)->handle(signedRequest(), passThrough())->getStatusCode())->toBe(200)
        ->and(verifier(now: SPEC_TIMESTAMP + 1000, tolerance: 1000)->handle(signedRequest(), passThrough())->getStatusCode())->toBe(200);
});

it('rejects missing or malformed headers before touching the signature', function (): void {
    foreach (['webhook-id', 'webhook-timestamp', 'webhook-signature'] as $header) {
        expect(fn () => verifier()->handle(signedRequest([$header => null]), passThrough()))
            ->toThrow(BadRequestHttpException::class, 'Missing Standard Webhooks headers');
    }

    expect(fn () => verifier()->handle(signedRequest(['webhook-timestamp' => '2021-02-25T15:02:10Z']), passThrough()))
        ->toThrow(BadRequestHttpException::class, 'Malformed webhook timestamp');

    expect(fn () => verifier()->handle(signedRequest(['webhook-signature' => 'v1']), passThrough()))
        ->toThrow(UnauthorizedHttpException::class, 'signature verification failed');
});

it('splits the config value into secrets', function (): void {
    expect(VerifyMinimailerWebhook::secretsFromConfig(null))->toBe([])
        ->and(VerifyMinimailerWebhook::secretsFromConfig('  '))->toBe([])
        ->and(VerifyMinimailerWebhook::secretsFromConfig('whsec_a'))->toBe(['whsec_a'])
        ->and(VerifyMinimailerWebhook::secretsFromConfig(' whsec_a , whsec_b,, '))->toBe(['whsec_a', 'whsec_b']);
});
