<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use MiniMailer\Laravel\Http\Middleware\VerifyMinimailerWebhook;
use MiniMailer\Laravel\Tests\Feature\TestCase;
use MiniMailer\Laravel\Webhooks\Events\EmailBounced;
use MiniMailer\Laravel\Webhooks\Events\WebhookReceived;
use MiniMailer\Laravel\Webhooks\WebhookController;
use MiniMailer\Laravel\Webhooks\WebhooksServiceProvider;

beforeEach(function (): void {
    $this->bootWithWebhookConfig(['path' => 'hooks/mm']);
});

afterEach(function (): void {
    TestCase::$webhookConfig = [];
});

it('leaves the route disabled for absent or unusable paths', function (mixed $path): void {
    $this->bootWithWebhookConfig(['path' => $path]);
    expect($this->webhookRoute())->toBeNull();
})->with([null, '', '   ', 42, [[]]]);

it('keeps automatic routing disabled without a webhooks config group', function (): void {
    $this->bootWithWebhookConfig(null);
    expect($this->webhookRoute())->toBeNull();
});

it('registers a named POST route with signature verification last', function (): void {
    $this->bootWithWebhookConfig(['path' => '  hooks/mm  ']);
    $route = $this->webhookRoute();
    expect($route->uri())->toBe('hooks/mm')->and($route->methods())->toBe(['POST'])
        ->and($route->getAction('controller'))->toBeIn([WebhookController::class, WebhookController::class.'@__invoke'])
        ->and($route->middleware())->toBe(['api', VerifyMinimailerWebhook::class]);

    // Feature tests skip the CSRF check, so prove the resolved stack has no session and no CSRF
    // middleware (VerifyCsrfToken / ValidateCsrfToken, PreventRequestForgery on Laravel 13).
    // The kernel registers the middleware groups on the router when it is first resolved.
    $this->app->make(Kernel::class);
    $resolved = $this->app['router']->gatherRouteMiddleware($route);
    $webOnly = array_filter($resolved, static fn (string $m): bool => (bool) preg_match('/Csrf|RequestForgery|StartSession/', $m));
    expect($webOnly)->toBe([])
        ->and($resolved)->not->toContain('api')
        ->and(end($resolved))->toBe(VerifyMinimailerWebhook::class);
});

it('accepts a signed delivery with a partially published config', function (): void {
    Event::fake([WebhookReceived::class, EmailBounced::class]);
    $this->postWebhook('{"type":"email.bounced","timestamp":"2026-11-10T08:30:00+00:00","data":{"bounce_type":"hard","recipient":"user@example.com"}}')
        ->assertNoContent();
    Event::assertDispatchedTimes(WebhookReceived::class, 1);
    Event::assertDispatched(EmailBounced::class, fn (EmailBounced $event): bool => $event->bounceType === 'hard' && $event->recipient === 'user@example.com');
    $this->travel(259199)->seconds();
    expect($this->hasDedupeKey())->toBeTrue();
    $this->travel(2)->seconds();
    expect($this->hasDedupeKey())->toBeFalse();
});

it('rejects an invalid signature before dispatch', function (): void {
    Event::fake([WebhookReceived::class, EmailBounced::class]);
    $this->postWebhook('{"type":"email.bounced","data":{}}', ['HTTP_WEBHOOK_SIGNATURE' => 'v1,invalid'])->assertUnauthorized();
    Event::assertNothingDispatched();
    expect($this->hasDedupeKey())->toBeFalse();
});

it('uses the configured cache store and TTL', function (): void {
    $this->bootWithWebhookConfig(['path' => 'hooks/mm', 'cache_store' => 'webhooks', 'dedupe_ttl' => 123]);
    $this->postWebhook('{"type":"email.bounced","data":{}}')->assertNoContent();
    expect($this->hasDedupeKey('webhooks'))->toBeTrue()
        ->and($this->hasDedupeKey())->toBeFalse();
    $this->travel(124)->seconds();
    expect($this->hasDedupeKey('webhooks'))->toBeFalse();
});

it('defaults invalid TTL and cache store shapes', function (mixed $ttl): void {
    $this->bootWithWebhookConfig(['path' => 'hooks/mm', 'dedupe_ttl' => $ttl, 'cache_store' => []]);
    $this->postWebhook('{"type":"other","data":{}}')->assertNoContent();
    $this->travel(259199)->seconds();
    expect($this->hasDedupeKey())->toBeTrue();
    $this->travel(2)->seconds();
    expect($this->hasDedupeKey())->toBeFalse();
})->with([0, -1, '123', 1.5, null, [[]]]);

it('normalizes middleware config', function (mixed $middleware, array $expected): void {
    $this->bootWithWebhookConfig(['path' => 'hooks/mm', 'middleware' => $middleware]);
    expect($this->webhookRoute()->middleware())->toBe([...$expected, VerifyMinimailerWebhook::class]);
})->with([
    ['api', ['api']], [null, ['api']], [42, ['api']],
    [['api', 42], ['api']], [[], []], ['web', ['web']],
]);

it('does not register routes when routes are cached', function (): void {
    $app = Mockery::mock(Application::class)->makePartial();
    $app->shouldReceive('routesAreCached')->once()->andReturn(true);
    $route = $this->webhookRoute();
    (new WebhooksServiceProvider($app))->boot();
    expect($this->webhookRoute())->toBe($route);
});
