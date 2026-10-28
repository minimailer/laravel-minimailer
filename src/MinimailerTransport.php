<?php

declare(strict_types=1);

namespace MiniMailer\Laravel;

use MiniMailer\Contracts\MiniMailerContract;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\UnstructuredHeader;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

/**
 * Symfony Mailer transport that sends through the MiniMailer v1 API, built
 * directly on the SDK and targeting the v1 `outbound-emails`
 * contract; decoupled from framework helpers so it is container-free testable.
 */
final class MinimailerTransport extends AbstractTransport
{
    private const HTTP_CREATED = 201;

    /**
     * Headers MiniMailer derives from the JSON:API attributes; they must not be
     * duplicated into the free-form headers bag.
     *
     * @var list<string>
     */
    private const STANDARD_HEADERS = ['from', 'to', 'cc', 'bcc', 'reply-to', 'sender', 'subject', 'content-type'];

    /**
     * Headers Symfony Mailer generates fresh for every send attempt. Forwarding
     * them would make a retried message's payload differ from the first attempt,
     * which turns an idempotent retry into a 409. The API sets its own anyway.
     *
     * @var list<string>
     */
    private const PER_ATTEMPT_HEADERS = ['message-id', 'date', 'mime-version'];

    /**
     * Opt-in idempotency: a mailable that sets this header has its value sent
     * as the API's `idempotency_key`, so a queue retry after a lost response
     * cannot deliver the same message twice. The header itself is never
     * forwarded. Keep the key stable across retries and unique per message
     * (an order id, a notification id), never a timestamp or a random value.
     */
    public const IDEMPOTENCY_KEY_HEADER = 'X-Minimailer-Idempotency-Key';

    public function __construct(
        private readonly MiniMailerContract $minimailer,
        private readonly string $domainId,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'minimailer';
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $envelope = $message->getEnvelope();

        try {
            $payload = [
                'data' => [
                    'type' => 'outbound-emails',
                    'attributes' => array_filter([
                        // The address and its display name travel separately: the API validates
                        // `from` as a bare email and builds the RFC 5322 From header from `from_name`.
                        'from' => ($email->getFrom()[0] ?? $email->getSender() ?? $envelope->getSender())?->getAddress(),
                        'from_name' => $this->getFromName($email, $envelope),
                        'to' => $this->getRecipient($email, $envelope)?->toString(),
                        'cc' => $this->stringifyAddresses($email->getCc()),
                        'bcc' => $this->stringifyAddresses($email->getBcc()),
                        'reply_to' => $this->stringifyAddresses($email->getReplyTo()),
                        'headers' => $this->getHeaders($email),
                        'subject' => (string) $email->getSubject(),
                        'html' => $email->getHtmlBody(),
                        'text' => $email->getTextBody(),
                        'attachments' => $this->getAttachments($email),
                        'idempotency_key' => $this->getIdempotencyKey($email),
                        // Drop only absent values: a plain array_filter would also drop the
                        // display name "0" and the subject "0".
                    ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []),
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
            $errorCode = is_int($throwable->getCode()) ? $throwable->getCode() : 0;

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
                'error_code' => $errorCode,
            ]);

            throw new TransportException(
                message: $errorMessage,
                code: $errorCode,
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

        foreach ($email->getHeaders()->all() as $name => $header) {
            if (in_array($name, self::STANDARD_HEADERS, true) || in_array($name, self::PER_ATTEMPT_HEADERS, true) || $name === strtolower(self::IDEMPOTENCY_KEY_HEADER)) {
                continue;
            }

            $headers[$header->getName()] = $header->getBodyAsString();
        }

        return $headers;
    }

    /**
     * The sender's display name, or null when the address carries none. A strict empty-string
     * check: a name like "0" is a valid display name and must not be dropped.
     */
    private function getFromName(Email $email, Envelope $envelope): ?string
    {
        $name = ($email->getFrom()[0] ?? $email->getSender() ?? $envelope->getSender())?->getName();

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function getIdempotencyKey(Email $email): ?string
    {
        $header = $email->getHeaders()->get(self::IDEMPOTENCY_KEY_HEADER);

        // getValue() is the raw text; getBodyAsString() would MIME-encode a
        // non-ASCII key into encoded words the API would not recognise.
        $key = $header instanceof UnstructuredHeader ? $header->getValue() : $header?->getBodyAsString();

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
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
