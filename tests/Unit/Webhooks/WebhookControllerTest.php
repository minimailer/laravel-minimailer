<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use MiniMailer\Laravel\Webhooks\Events\WebhookReceived;
use MiniMailer\Laravel\Webhooks\WebhookController;

function webhookControllerRequest(string $body, ?string $id = 'delivery_1', ?string $timestamp = '1794299400'): Request
{
    $request = Request::create('/hooks/mm', 'POST', content: $body);
    if ($id !== null) {
        $request->headers->set('webhook-id', $id);
    }
    if ($timestamp !== null) {
        $request->headers->set('webhook-timestamp', $timestamp);
    }

    return $request;
}

beforeEach(function (): void {
    $this->cache = new Repository(new ArrayStore);
    $this->events = new Dispatcher;
    $this->recorded = [];
    $this->events->listen('*', function (string $name, array $payload): void {
        $this->recorded[] = $payload[0];
    });
    $this->controller = new WebhookController($this->cache, $this->events);
});

it('dispatches the envelope first and maps every email field', function (string $type, array $fields): void {
    $data = webhookEmailData();
    $response = ($this->controller)(webhookControllerRequest(json_encode(['type' => $type, 'timestamp' => '2026-11-10T08:30:00+00:00', 'data' => $data], JSON_THROW_ON_ERROR)));
    expect($response->getStatusCode())->toBe(204)->and($response->getContent())->toBe('')
        ->and($this->recorded)->toHaveCount(2)->and($this->recorded[0])->toBeInstanceOf(WebhookReceived::class);
    $event = $this->recorded[1];
    expect($event)->toBeInstanceOf('MiniMailer\\Laravel\\Webhooks\\Events\\Email'.ucfirst(substr($type, 6)));
    foreach ($this->recorded as $received) {
        expect($received->webhookId)->toBe('delivery_1')->and($received->type)->toBe($type)
            ->and($received->timestamp->format(DATE_ATOM))->toBe('2026-11-10T08:30:00+00:00')->and($received->data)->toBe($data);
    }
    foreach (['sendId', 'recipient', 'domain', ...$fields] as $property) {
        $key = strtolower((string) preg_replace('/[A-Z]/', '_$0', $property));
        $expected = $property === 'affectedRecipients' ? ['user@example.com', 'other@example.com'] : $data[$key];
        $actual = $event->$property;
        expect($actual instanceof DateTimeImmutable ? $actual->format(DATE_ATOM) : $actual)->toBe($expected);
    }
})->with(webhookEventCases());

it('accepts unknown types as generic events', function (string $type): void {
    expect(($this->controller)(webhookControllerRequest(json_encode(['type' => $type, 'data' => []], JSON_THROW_ON_ERROR)))->getStatusCode())->toBe(204)
        ->and($this->recorded)->toHaveCount(1)->and($this->recorded[0])->toBeInstanceOf(WebhookReceived::class);
})->with(['campaign.sent', 'inbound_email.received', 'domain.verified', 'future.event']);

it('rejects malformed deliveries before deduplicating', function (string $body, ?string $id): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldNotReceive('add');
    expect((new WebhookController($cache, $this->events))(webhookControllerRequest($body, $id))->getStatusCode())->toBe(400)
        ->and($this->recorded)->toBe([]);
})->with([
    ['{', 'delivery_1'], ['null', 'delivery_1'], ['42', 'delivery_1'], ['"text"', 'delivery_1'],
    ['[]', 'delivery_1'], ['{"data":{}}', 'delivery_1'], ['{"type":42,"data":{}}', 'delivery_1'],
    ['{"type":[],"data":{}}', 'delivery_1'], ['{"type":"","data":{}}', 'delivery_1'],
    ['{"type":"email.sent"}', 'delivery_1'], ['{"type":"email.sent","data":null}', 'delivery_1'],
    ['{"type":"email.sent","data":"x"}', 'delivery_1'], ['{"type":"email.sent","data":{}}', null],
    ['{"type":"email.sent","data":{}}', ''],
]);

it('deduplicates delivery ids and accepts the same body with a new id', function (): void {
    $body = '{"type":"email.bounced","data":{}}';
    expect(($this->controller)(webhookControllerRequest($body))->getStatusCode())->toBe(204);
    $this->recorded = [];
    expect(($this->controller)(webhookControllerRequest($body))->getStatusCode())->toBe(200)->and($this->recorded)->toBe([]);
    expect(($this->controller)(webhookControllerRequest($body, 'delivery_2'))->getStatusCode())->toBe(204)->and($this->recorded)->toHaveCount(2);
});

it('keeps the dedupe key when a listener throws', function (): void {
    $calls = 0;
    $this->events->listen(WebhookReceived::class, function () use (&$calls): void {
        $calls++;
        throw new RuntimeException('Listener failed');
    });
    $request = webhookControllerRequest('{"type":"email.bounced","data":{}}');
    expect(fn () => ($this->controller)($request))->toThrow(RuntimeException::class, 'Listener failed');
    expect(($this->controller)($request)->getStatusCode())->toBe(200)->and($calls)->toBe(1);
});

it('maps missing and wrong typed fields leniently', function (string $type, array $fields): void {
    foreach ([[], array_fill_keys(array_keys(webhookEmailData()), [42]), array_fill_keys(array_keys(webhookEmailData()), 42)] as $data) {
        ($this->controller)(webhookControllerRequest(json_encode(['type' => $type, 'data' => $data], JSON_THROW_ON_ERROR), uniqid()));
        $event = end($this->recorded);
        foreach (['sendId', 'recipient', 'domain', ...$fields] as $field) {
            expect($event->$field)->toBe($field === 'affectedRecipients' ? [] : null);
        }
    }
})->with(webhookEventCases());

it('rejects invalid dates and scalar list and boolean values', function (): void {
    ($this->controller)(webhookControllerRequest('{"type":"email.bounced","data":{"suppressed":"yes","affected_recipients":"x","bounced_at":"not a date"}}'));
    expect($this->recorded[1]->suppressed)->toBeNull()->and($this->recorded[1]->affectedRecipients)->toBe([])->and($this->recorded[1]->bouncedAt)->toBeNull();
});

it('prefers a valid body timestamp over the header', function (): void {
    // The default header time is 2026-11-10T08:30:00Z, so the body uses a different instant.
    ($this->controller)(webhookControllerRequest('{"type":"other","data":{},"timestamp":"2026-01-02T03:04:05+00:00"}'));
    expect($this->recorded[0]->timestamp->format(DATE_ATOM))->toBe('2026-01-02T03:04:05+00:00');
});

it('falls back to the header for missing or invalid body timestamps', function (mixed $timestamp): void {
    ($this->controller)(webhookControllerRequest(json_encode(['type' => 'other', 'data' => [], 'timestamp' => $timestamp], JSON_THROW_ON_ERROR)));
    expect($this->recorded[0]->timestamp->getTimestamp())->toBe(1794299400);
})->with([null, '', 'not a date', '2026-02-30T00:00:00+00:00', "invalid\0date", 42, [[]]]);

it('uses now when both timestamp sources fail', function (?string $header): void {
    $before = time();
    ($this->controller)(webhookControllerRequest('{"type":"other","data":{}}', timestamp: $header));
    expect($this->recorded[0]->timestamp->getTimestamp())->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual(time());
})->with([null, '', 'bad', '999999999999999999999999']);

it('fails instead of acknowledging when the cache write fails', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('add')->once()->andReturn(false);
    $cache->shouldReceive('has')->once()->with('minimailer:webhook:delivery_1')->andReturn(false);
    expect((new WebhookController($cache, $this->events))(webhookControllerRequest('{"type":"email.bounced","data":{}}'))->getStatusCode())->toBe(503)
        ->and($this->recorded)->toBe([]);
});

it('uses the supplied dedupe TTL', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('add')->once()->with('minimailer:webhook:delivery_1', true, 123)->andReturn(true);
    expect((new WebhookController($cache, $this->events, 123))(webhookControllerRequest('{"type":"other","data":{}}'))->getStatusCode())->toBe(204);
});
