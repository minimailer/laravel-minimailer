<?php

declare(strict_types=1);

namespace MiniMailer\Laravel\Webhooks\Events;

use DateTimeImmutable;
use MiniMailer\Laravel\Webhooks\Payload;

final readonly class EmailRejected extends EmailEvent
{
    public ?string $reason;

    public ?DateTimeImmutable $rejectedAt;

    /** @param array<string, mixed> $data */
    public function __construct(string $webhookId, string $type, DateTimeImmutable $timestamp, array $data)
    {
        parent::__construct($webhookId, $type, $timestamp, $data);
        $this->reason = Payload::string($data['reason'] ?? null);
        $this->rejectedAt = Payload::date($data['rejected_at'] ?? null);
    }
}
