<?php

declare(strict_types=1);

namespace MiniMailer\Laravel\Webhooks;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use MiniMailer\Laravel\Webhooks\Events\EmailBounced;
use MiniMailer\Laravel\Webhooks\Events\EmailClicked;
use MiniMailer\Laravel\Webhooks\Events\EmailComplained;
use MiniMailer\Laravel\Webhooks\Events\EmailDelayed;
use MiniMailer\Laravel\Webhooks\Events\EmailDelivered;
use MiniMailer\Laravel\Webhooks\Events\EmailEvent;
use MiniMailer\Laravel\Webhooks\Events\EmailOpened;
use MiniMailer\Laravel\Webhooks\Events\EmailRejected;
use MiniMailer\Laravel\Webhooks\Events\EmailSent;
use MiniMailer\Laravel\Webhooks\Events\EmailUnsubscribed;
use MiniMailer\Laravel\Webhooks\Events\WebhookReceived;
use Symfony\Component\HttpFoundation\Response;

final class WebhookController
{
    /** @var array<string, class-string<EmailEvent>> */
    public const EVENT_TYPES = [
        'email.sent' => EmailSent::class,
        'email.delivered' => EmailDelivered::class,
        'email.delayed' => EmailDelayed::class,
        'email.bounced' => EmailBounced::class,
        'email.complained' => EmailComplained::class,
        'email.opened' => EmailOpened::class,
        'email.clicked' => EmailClicked::class,
        'email.rejected' => EmailRejected::class,
        'email.unsubscribed' => EmailUnsubscribed::class,
    ];

    public function __construct(
        private readonly Repository $cache,
        private readonly Dispatcher $events,
        private readonly int $dedupeTtl = 259200,
    ) {}

    public function __invoke(Request $request): Response
    {
        $body = json_decode($request->getContent(), true);
        $id = $request->headers->get('webhook-id');

        if (! is_array($body) || ! is_string($body['type'] ?? null) || $body['type'] === ''
            || ! is_array($body['data'] ?? null) || $id === null || $id === '') {
            return new Response('', 400);
        }

        $key = 'minimailer:webhook:'.$id;

        if (! $this->cache->add($key, true, $this->dedupeTtl)) {
            // Some stores (Memcached) also return false when the write fails. Acknowledge only a
            // delivery we have really seen; otherwise fail so MiniMailer retries it later.
            return $this->cache->has($key) ? new Response('', 200) : new Response('', 503);
        }

        $envelope = new WebhookReceived($id, $body['type'], $this->timestamp($body, $request), $body['data']);
        $this->events->dispatch($envelope);

        $eventClass = self::EVENT_TYPES[$envelope->type] ?? null;

        if ($eventClass !== null) {
            $this->events->dispatch($eventClass::fromEnvelope($envelope));
        }

        return new Response('', 204);
    }

    /**
     * The envelope timestamp, then the signed webhook-timestamp header, then now.
     *
     * @param  array<array-key, mixed>  $body
     */
    private function timestamp(array $body, Request $request): DateTimeImmutable
    {
        $timestamp = Payload::date($body['timestamp'] ?? null);

        if ($timestamp !== null) {
            return $timestamp;
        }

        $header = $request->headers->get('webhook-timestamp');

        if (is_string($header) && preg_match('/^\d{1,12}$/', $header) === 1) {
            return (new DateTimeImmutable)->setTimestamp((int) $header);
        }

        return new DateTimeImmutable;
    }
}
