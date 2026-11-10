<?php

declare(strict_types=1);

namespace MiniMailer\Laravel\Webhooks\Events;

use DateTimeImmutable;

final readonly class WebhookReceived
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $webhookId,
        public string $type,
        public DateTimeImmutable $timestamp,
        public array $data,
    ) {}
}
