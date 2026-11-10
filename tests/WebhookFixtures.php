<?php

declare(strict_types=1);

/** @return array<string, mixed> */
function webhookEmailData(): array
{
    return [
        'send_id' => 'send_123', 'recipient' => 'user@example.com', 'domain' => 'example.com',
        'sendable_type' => 'campaign_send', 'sendable_id' => 'sendable_123', 'subject' => 'Hello',
        'delay_type' => 'temporary', 'bounce_type' => 'hard', 'affected_recipients' => ['user@example.com', 42, 'other@example.com'],
        'bounce_subtype' => 'General', 'diagnostic_code' => 'smtp; 550', 'suppressed' => true,
        'complaint_type' => 'abuse', 'ip_address' => '192.0.2.1', 'user_agent' => 'Example browser',
        'link' => 'https://example.com', 'reason' => 'policy',
        ...array_fill_keys(['sent_at', 'delivered_at', 'expires_at', 'delayed_at', 'bounced_at', 'complained_at', 'opened_at', 'clicked_at', 'rejected_at', 'unsubscribed_at'], '2026-11-10T08:29:58+00:00'),
    ];
}

/** @return array<string, array{string, list<string>}> */
function webhookEventCases(): array
{
    return [
        'sent' => ['email.sent', ['sendableType', 'sendableId', 'subject', 'sentAt']],
        'delivered' => ['email.delivered', ['deliveredAt']],
        'delayed' => ['email.delayed', ['delayType', 'expiresAt', 'delayedAt']],
        'bounced' => ['email.bounced', ['bounceType', 'affectedRecipients', 'bounceSubtype', 'diagnosticCode', 'suppressed', 'bouncedAt']],
        'complained' => ['email.complained', ['complaintType', 'affectedRecipients', 'suppressed', 'complainedAt']],
        'opened' => ['email.opened', ['ipAddress', 'userAgent', 'openedAt']],
        'clicked' => ['email.clicked', ['link', 'ipAddress', 'userAgent', 'clickedAt']],
        'rejected' => ['email.rejected', ['reason', 'rejectedAt']],
        'unsubscribed' => ['email.unsubscribed', ['unsubscribedAt']],
    ];
}
