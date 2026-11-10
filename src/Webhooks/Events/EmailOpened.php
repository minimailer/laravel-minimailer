<?php

declare(strict_types=1);

namespace MiniMailer\Laravel\Webhooks\Events;

use DateTimeImmutable;
use MiniMailer\Laravel\Webhooks\Payload;

final readonly class EmailOpened extends EmailEvent
{
    public ?string $ipAddress;

    public ?string $userAgent;

    public ?DateTimeImmutable $openedAt;

    /** @param array<string, mixed> $data */
    public function __construct(string $webhookId, string $type, DateTimeImmutable $timestamp, array $data)
    {
        parent::__construct($webhookId, $type, $timestamp, $data);
        $this->ipAddress = Payload::string($data['ip_address'] ?? null);
        $this->userAgent = Payload::string($data['user_agent'] ?? null);
        $this->openedAt = Payload::date($data['opened_at'] ?? null);
    }
}
