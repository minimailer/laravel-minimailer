<?php

declare(strict_types=1);

namespace MiniMailer\Laravel\Webhooks\Events;

use DateTimeImmutable;
use MiniMailer\Laravel\Webhooks\Payload;

final readonly class EmailSent extends EmailEvent
{
    public ?string $sendableType;

    public ?string $sendableId;

    public ?string $subject;

    public ?DateTimeImmutable $sentAt;

    /** @param array<string, mixed> $data */
    public function __construct(string $webhookId, string $type, DateTimeImmutable $timestamp, array $data)
    {
        parent::__construct($webhookId, $type, $timestamp, $data);
        $this->sendableType = Payload::string($data['sendable_type'] ?? null);
        $this->sendableId = Payload::string($data['sendable_id'] ?? null);
        $this->subject = Payload::string($data['subject'] ?? null);
        $this->sentAt = Payload::date($data['sent_at'] ?? null);
    }
}
