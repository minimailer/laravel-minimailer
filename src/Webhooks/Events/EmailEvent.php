<?php

declare(strict_types=1);

namespace MiniMailer\Laravel\Webhooks\Events;

use DateTimeImmutable;
use MiniMailer\Laravel\Webhooks\Payload;

abstract readonly class EmailEvent
{
    public ?string $sendId;

    public ?string $recipient;

    public ?string $domain;

    /** @param array<string, mixed> $data */
    public function __construct(
        public string $webhookId,
        public string $type,
        public DateTimeImmutable $timestamp,
        public array $data,
    ) {
        $this->sendId = Payload::string($data['send_id'] ?? null);
        $this->recipient = Payload::string($data['recipient'] ?? null);
        $this->domain = Payload::string($data['domain'] ?? null);
    }

    public static function fromEnvelope(WebhookReceived $envelope): static
    {
        return new static($envelope->webhookId, $envelope->type, $envelope->timestamp, $envelope->data);
    }
}
