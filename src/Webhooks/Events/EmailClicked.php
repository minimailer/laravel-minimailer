<?php

declare(strict_types=1);

namespace MiniMailer\Laravel\Webhooks\Events;

use DateTimeImmutable;
use MiniMailer\Laravel\Webhooks\Payload;

final readonly class EmailClicked extends EmailEvent
{
    public ?string $link;

    public ?string $ipAddress;

    public ?string $userAgent;

    public ?DateTimeImmutable $clickedAt;

    /** @param array<string, mixed> $data */
    public function __construct(string $webhookId, string $type, DateTimeImmutable $timestamp, array $data)
    {
        parent::__construct($webhookId, $type, $timestamp, $data);
        $this->link = Payload::string($data['link'] ?? null);
        $this->ipAddress = Payload::string($data['ip_address'] ?? null);
        $this->userAgent = Payload::string($data['user_agent'] ?? null);
        $this->clickedAt = Payload::date($data['clicked_at'] ?? null);
    }
}
