<?php

declare(strict_types=1);

namespace MiniMailer\Laravel\Webhooks\Events;

use DateTimeImmutable;
use MiniMailer\Laravel\Webhooks\Payload;

final readonly class EmailComplained extends EmailEvent
{
    public ?string $complaintType;

    /** @var list<string> */
    public array $affectedRecipients;

    public ?bool $suppressed;

    public ?DateTimeImmutable $complainedAt;

    /** @param array<string, mixed> $data */
    public function __construct(string $webhookId, string $type, DateTimeImmutable $timestamp, array $data)
    {
        parent::__construct($webhookId, $type, $timestamp, $data);
        $this->complaintType = Payload::string($data['complaint_type'] ?? null);
        $this->affectedRecipients = Payload::strings($data['affected_recipients'] ?? null);
        $this->suppressed = Payload::boolean($data['suppressed'] ?? null);
        $this->complainedAt = Payload::date($data['complained_at'] ?? null);
    }
}
