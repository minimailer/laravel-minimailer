<?php

declare(strict_types=1);

namespace MiniMailer\Laravel\Webhooks\Events;

use DateTimeImmutable;
use MiniMailer\Laravel\Webhooks\Payload;

final readonly class EmailBounced extends EmailEvent
{
    public ?string $bounceType;

    /** @var list<string> */
    public array $affectedRecipients;

    public ?string $bounceSubtype;

    public ?string $diagnosticCode;

    public ?bool $suppressed;

    public ?DateTimeImmutable $bouncedAt;

    /** @param array<string, mixed> $data */
    public function __construct(string $webhookId, string $type, DateTimeImmutable $timestamp, array $data)
    {
        parent::__construct($webhookId, $type, $timestamp, $data);
        $this->bounceType = Payload::string($data['bounce_type'] ?? null);
        $this->affectedRecipients = Payload::strings($data['affected_recipients'] ?? null);
        $this->bounceSubtype = Payload::string($data['bounce_subtype'] ?? null);
        $this->diagnosticCode = Payload::string($data['diagnostic_code'] ?? null);
        $this->suppressed = Payload::boolean($data['suppressed'] ?? null);
        $this->bouncedAt = Payload::date($data['bounced_at'] ?? null);
    }
}
