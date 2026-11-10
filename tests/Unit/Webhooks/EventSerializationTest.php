<?php

declare(strict_types=1);

use MiniMailer\Laravel\Webhooks\Events\WebhookReceived;
use MiniMailer\Laravel\Webhooks\WebhookController;

it('round trips every event with all properties intact', function (string $class): void {
    $envelope = new WebhookReceived('delivery_1', 'email.bounced', new DateTimeImmutable('2026-11-10T08:30:00+00:00'), webhookEmailData());
    $event = $class === WebhookReceived::class ? $envelope : $class::fromEnvelope($envelope);
    $restored = unserialize(serialize($event));
    expect($restored)->toBeInstanceOf($class)->toEqual($event)
        ->and(get_object_vars($restored))->toEqual(get_object_vars($event));
})->with(fn (): array => [WebhookReceived::class, ...array_values(WebhookController::EVENT_TYPES)]);
