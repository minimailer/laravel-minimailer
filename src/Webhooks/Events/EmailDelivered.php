<?php

declare(strict_types=1);

namespace MiniMailer\Laravel\Webhooks\Events;

use DateTimeImmutable;
use MiniMailer\Laravel\Webhooks\Payload;

final readonly class EmailDelivered extends EmailEvent
{
    public ?DateTimeImmutable $deliveredAt;

    /** @param array<string, mixed> $data */
    public function __construct(string $webhookId, string $type, DateTimeImmutable $timestamp, array $data)
    {
        parent::__construct($webhookId, $type, $timestamp, $data);
        $this->deliveredAt = Payload::date($data['delivered_at'] ?? null);
    }
}
