<?php

declare(strict_types=1);

namespace MiniMailer\Laravel\Webhooks\Events;

use DateTimeImmutable;
use MiniMailer\Laravel\Webhooks\Payload;

final readonly class EmailDelayed extends EmailEvent
{
    public ?string $delayType;

    public ?DateTimeImmutable $expiresAt;

    public ?DateTimeImmutable $delayedAt;

    /** @param array<string, mixed> $data */
    public function __construct(string $webhookId, string $type, DateTimeImmutable $timestamp, array $data)
    {
        parent::__construct($webhookId, $type, $timestamp, $data);
        $this->delayType = Payload::string($data['delay_type'] ?? null);
        $this->expiresAt = Payload::date($data['expires_at'] ?? null);
        $this->delayedAt = Payload::date($data['delayed_at'] ?? null);
    }
}
