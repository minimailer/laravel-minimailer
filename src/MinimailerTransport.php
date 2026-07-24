<?php

declare(strict_types=1);

namespace MiniMailer\Laravel;

use MiniMailer\Contracts\MiniMailerContract;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

/**
 * Symfony Mailer transport that sends through the MiniMailer v1 API. Ported
 * from anooi's production transport and retargeted to the v1 `outbound-emails`
 * contract; decoupled from framework helpers so it is container-free testable.
 */
final class MinimailerTransport extends AbstractTransport
{
    private const HTTP_CREATED = 201;

    public function __construct(
        private readonly MiniMailerContract $minimailer,
        private readonly string $domainId,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    #[Override]
    public function __toString(): string
    {
        return 'minimailer';
    }

    #[Override]
    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $envelope = $message->getEnvelope();

        try {
            $payload = [
                'data' => [
                    'type' => 'outbound-emails',
                    'attributes' => array_filter([
                        'from' => ($email->getFrom()[0] ?? $email->getSender() ?? $envelope->getSender())?->toString(),
                        'to' => (string) $this->getRecipient($email, $envelope)?->toString(),
                        'cc' => $this->stringifyAddresses($email->getCc()),
                        'bcc' => $this->stringifyAddresses($email->getBcc()),
                        'reply_to' => $this->stringifyAddresses($email->getReplyTo()),
                        'headers' => $this->getHeaders($email),
                        'subject' => (string) $email->getSubject(),
                        'html' => $email->getHtmlBody(),
                        'text' => $email->getTextBody(),
                        'attachments' => $this->getAttachments($email),
                    ]),
                    'relationships' => [
                        'domain' => [
                            'type' => 'domains',
                            'id' => $this->domainId,
                        ],
                    ],
                ],
            ];

            $response = $this->minimailer->emails()->create($payload);

            $statusCode = $response->status();

            if ($statusCode !== self::HTTP_CREATED) {
                throw new TransportException($response->body(), $statusCode);
            }
        } catch (Throwable $throwable) {
            $errorMessage = sprintf('Request to MiniMailer API failed: %s', $throwable->getMessage());

            // Never log the full payload: it carries recipient emails, CC/BCC,
            // reply-to, subject, message body and base64-encoded attachments.
            // Log only redacted, non-PII metadata that ops need to triage.
            $this->logger?->info($errorMessage, [
                'mailer' => 'minimailer',
                'recipient_count' => count($envelope->getRecipients()),
                'cc_count' => count($email->getCc()),
                'bcc_count' => count($email->getBcc()),
                'attachment_count' => count($email->getAttachments()),
                'subject_length' => mb_strlen((string) $email->getSubject()),
                'http_status' => isset($response) ? $response->status() : null,
                'error_code' => is_int($throwable->getCode()) ? $throwable->getCode() : 0,
            ]);

            throw new TransportException(
                message: $errorMessage,
                code: is_int($throwable->getCode()) ? $throwable->getCode() : 0,
                previous: $throwable,
            );
        }

        $messageId = $response->json('data.id');

        $email->getHeaders()->addHeader('X-Minimailer-Message-Id', is_string($messageId) ? $messageId : '');
    }

    /**
     * Resolve the primary recipient, excluding CC and BCC addresses.
     */
    private function getRecipient(Email $email, Envelope $envelope): ?Address
    {
        $excluded = array_merge($email->getCc(), $email->getBcc());

        $recipients = array_values(array_filter(
            $envelope->getRecipients(),
            fn (Address $address): bool => ! in_array($address, $excluded, true),
        ));

        return $recipients[0] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function getHeaders(Email $email): array
    {
        $headers = [];

        $headersToBypass = ['from', 'to', 'cc', 'bcc', 'reply-to', 'sender', 'subject', 'content-type'];

        foreach ($email->getHeaders()->all() as $name => $header) {
            if (in_array($name, $headersToBypass, true)) {
                continue;
            }

            $headers[$header->getName()] = $header->getBodyAsString();
        }

        return $headers;
    }

    /**
     * @return array<int, array<string, string>>|null
     */
    private function getAttachments(Email $email): ?array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $attachmentHeaders = $attachment->getPreparedHeaders();

            $contentType = $attachmentHeaders->get('Content-Type')->getBody();
            $disposition = $attachmentHeaders->getHeaderBody('Content-Disposition');
            $filename = $attachmentHeaders->getHeaderParameter('Content-Disposition', 'filename');

            $item = [
                'content_type' => $contentType,
                'content' => base64_encode($attachment->getBody()),
                'filename' => $filename,
            ];

            if ($disposition === 'inline') {
                $item['content_id'] = $attachment->hasContentId() ? $attachment->getContentId() : $filename;
            }

            $attachments[] = $item;
        }

        return $attachments === [] ? null : $attachments;
    }
}
