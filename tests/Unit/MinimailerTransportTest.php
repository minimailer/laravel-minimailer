<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use MiniMailer\Client;
use MiniMailer\Laravel\MinimailerTransport;
use MiniMailer\MiniMailer;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Build a transport whose SDK replays the given canned HTTP responses, and
 * expose the captured request history by reference.
 *
 * @param  array<int, GuzzleResponse>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function transportReplaying(array $responses, ?array &$history = null, string $domainId = 'dom_123'): MinimailerTransport
{
    $history ??= [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $mailer = new MiniMailer(new Client('key', 'https://api.minimailer.app/', new GuzzleClient(['handler' => $stack])));

    return new MinimailerTransport($mailer, $domainId);
}

it('sends through the v1 outbound-emails contract and stamps the message id', function (): void {
    $history = [];
    $transport = transportReplaying([
        new GuzzleResponse(201, [], (string) json_encode(['data' => ['id' => 'email_01JZX8K7Q2M3N4P5R6S7T8V9W2']])),
    ], $history);

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->replyTo('reply@example.com')
        ->subject('Welcome')
        ->text('Hello there')
        ->html('<p>Hello there</p>');

    // AbstractTransport::send() clones the message, so the stamped header
    // lands on the returned SentMessage's message, not the original $email.
    $sent = $transport->send($email);

    $request = $history[0]['request'];
    $payload = json_decode((string) $request->getBody(), true);

    expect((string) $request->getUri())->toBe('https://api.minimailer.app/outbound-emails')
        ->and($request->getMethod())->toBe('POST')
        ->and($payload['data']['type'])->toBe('outbound-emails')
        ->and($payload['data']['attributes']['from'])->toBe('sender@example.com')
        ->and($payload['data']['attributes']['to'])->toBe('recipient@example.com')
        ->and($payload['data']['attributes']['reply_to'])->toBe(['reply@example.com'])
        ->and($payload['data']['attributes']['subject'])->toBe('Welcome')
        ->and($payload['data']['attributes']['html'])->toBe('<p>Hello there</p>')
        ->and($payload['data']['relationships']['domain'])->toBe(['type' => 'domains', 'id' => 'dom_123'])
        ->and($sent?->getOriginalMessage()->getHeaders()->get('X-Minimailer-Message-Id')?->getBodyAsString())
        ->toBe('email_01JZX8K7Q2M3N4P5R6S7T8V9W2');
});

it('base64-encodes attachments and carries content_id for inline parts', function (): void {
    $history = [];
    $transport = transportReplaying([
        new GuzzleResponse(201, [], (string) json_encode(['data' => ['id' => 'email_01ABC']])),
    ], $history);

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('With attachments')
        ->html('<p>See attached</p>')
        ->addPart((new DataPart('image-bytes', 'logo.png', 'image/png'))->asInline())
        ->addPart(new DataPart('doc-bytes', 'notes.txt', 'text/plain'));

    $transport->send($email);

    $attachments = json_decode((string) $history[0]['request']->getBody(), true)['data']['attributes']['attachments'];

    $inline = collect($attachments)->firstWhere('filename', 'logo.png');
    $regular = collect($attachments)->firstWhere('filename', 'notes.txt');

    expect($inline['content'])->toBe(base64_encode('image-bytes'))
        ->and($inline['content_type'])->toContain('image/png')
        ->and($inline)->toHaveKey('content_id')
        ->and($regular['content'])->toBe(base64_encode('doc-bytes'))
        ->and($regular)->not->toHaveKey('content_id');
});

it('forwards an opt-in idempotency key as an attribute and never as a header', function (): void {
    $history = [];
    $transport = transportReplaying([
        new GuzzleResponse(201, [], '{"data":{"id":"email_01ABC"}}'),
        new GuzzleResponse(201, [], '{"data":{"id":"email_01ABD"}}'),
    ], $history);

    $keyed = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Order confirmed')
        ->html('<p>Thanks</p>');
    $keyed->getHeaders()->addTextHeader(MinimailerTransport::IDEMPOTENCY_KEY_HEADER, ' order-confirmed:01JZX:'.str_repeat('é', 40).' ');
    $keyed->getHeaders()->addTextHeader('X-Campaign', 'spring');
    $keyed->getHeaders()->addDateHeader('Date', new DateTimeImmutable('2026-10-28 10:00:00'));
    $keyed->getHeaders()->addIdHeader('Message-ID', 'first-attempt@example.com');

    $plain = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('No key')
        ->html('<p>Hi</p>');

    $transport->send($keyed);
    $transport->send($plain);

    $keyedAttributes = json_decode((string) $history[0]['request']->getBody(), true)['data']['attributes'];
    $plainAttributes = json_decode((string) $history[1]['request']->getBody(), true)['data']['attributes'];

    // The raw Unicode key, not a MIME-encoded word; per-attempt headers (Message-ID, Date,
    // MIME-Version) are not forwarded, so a retried payload is byte-identical.
    expect($keyedAttributes['idempotency_key'])->toBe('order-confirmed:01JZX:'.str_repeat('é', 40))
        ->and($keyedAttributes['headers'])->toBe(['X-Campaign' => 'spring'])
        ->and($plainAttributes)->not->toHaveKey('idempotency_key')
        ->and($plainAttributes['headers'] ?? [])->toBe([]);
});

it('throws a TransportException when the API does not return 201', function (): void {
    $transport = transportReplaying([
        new GuzzleResponse(200, [], '{"data":{"id":"email_01ABC"}}'),
    ]);

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Nope')
        ->html('<p>Nope</p>');

    expect(fn () => $transport->send($email))->toThrow(TransportException::class);
});

it('wraps an SDK API error as a TransportException', function (): void {
    $transport = transportReplaying([
        new GuzzleResponse(422, [], '{"errors":[{"detail":"The from field is required."}]}'),
    ]);

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Invalid')
        ->html('<p>Invalid</p>');

    expect(fn () => $transport->send($email))->toThrow(TransportException::class);
});
