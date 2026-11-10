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

/** @return array<string, string> */
function webhookRouteHeaders(string $body, string $secret, string $id = 'delivery_route'): array
{
    $timestamp = time();
    $key = (string) base64_decode(substr($secret, 6), true);

    return [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $id,
        'HTTP_WEBHOOK_TIMESTAMP' => (string) $timestamp,
        'HTTP_WEBHOOK_SIGNATURE' => 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $key, true)),
    ];
}

beforeEach(function (): void {
    TestCase::$webhookConfig = ['path' => 'hooks/mm'];
    $this->refreshApplication();
});

afterEach(function (): void {
    TestCase::$webhookConfig = [];
});

it('leaves the route disabled for absent or unusable paths', function (mixed $path): void {
    TestCase::$webhookConfig = ['path' => $path];
    $this->refreshApplication();
    expect($this->app['router']->getRoutes()->getByName('minimailer.webhook'))->toBeNull();
})->with([null, '', '   ', 42, [[]]]);

it('keeps automatic routing disabled without a webhooks config group', function (): void {
    TestCase::$webhookConfig = null;
    $this->refreshApplication();
    expect($this->app['router']->getRoutes()->getByName('minimailer.webhook'))->toBeNull();
});

it('registers a named POST route with signature verification last', function (): void {
    TestCase::$webhookConfig = ['path' => '  hooks/mm  '];
    $this->refreshApplication();
    $route = $this->app['router']->getRoutes()->getByName('minimailer.webhook');
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
    $body = '{"type":"email.bounced","timestamp":"2026-11-10T08:30:00+00:00","data":{"bounce_type":"hard","recipient":"user@example.com"}}';
    $this->call('POST', '/hooks/mm', server: webhookRouteHeaders($body, config('minimailer.webhook_secret')), content: $body)->assertNoContent();
    Event::assertDispatchedTimes(WebhookReceived::class, 1);
    Event::assertDispatched(EmailBounced::class, fn (EmailBounced $event): bool => $event->bounceType === 'hard' && $event->recipient === 'user@example.com');
    $this->travel(259199)->seconds();
    expect($this->app['cache']->store()->has('minimailer:webhook:delivery_route'))->toBeTrue();
    $this->travel(2)->seconds();
    expect($this->app['cache']->store()->has('minimailer:webhook:delivery_route'))->toBeFalse();
});

it('rejects an invalid signature before dispatch', function (): void {
    Event::fake([WebhookReceived::class, EmailBounced::class]);
    $body = '{"type":"email.bounced","data":{}}';
    $headers = webhookRouteHeaders($body, config('minimailer.webhook_secret'));
    $headers['HTTP_WEBHOOK_SIGNATURE'] = 'v1,invalid';
    $this->call('POST', '/hooks/mm', server: $headers, content: $body)->assertUnauthorized();
    Event::assertNothingDispatched();
    expect($this->app['cache']->store()->has('minimailer:webhook:delivery_route'))->toBeFalse();
});

it('uses the configured cache store and TTL', function (): void {
    TestCase::$webhookConfig = ['path' => 'hooks/mm', 'cache_store' => 'webhooks', 'dedupe_ttl' => 123];
    $this->refreshApplication();
    $body = '{"type":"email.bounced","data":{}}';
    $this->call('POST', '/hooks/mm', server: webhookRouteHeaders($body, config('minimailer.webhook_secret')), content: $body)->assertNoContent();
    expect($this->app['cache']->store('webhooks')->has('minimailer:webhook:delivery_route'))->toBeTrue()
        ->and($this->app['cache']->store()->has('minimailer:webhook:delivery_route'))->toBeFalse();
    $this->travel(124)->seconds();
    expect($this->app['cache']->store('webhooks')->has('minimailer:webhook:delivery_route'))->toBeFalse();
});

it('defaults invalid TTL and cache store shapes', function (mixed $ttl): void {
    TestCase::$webhookConfig = ['path' => 'hooks/mm', 'dedupe_ttl' => $ttl, 'cache_store' => []];
    $this->refreshApplication();
    $body = '{"type":"other","data":{}}';
    $this->call('POST', '/hooks/mm', server: webhookRouteHeaders($body, config('minimailer.webhook_secret')), content: $body)->assertNoContent();
    $this->travel(259199)->seconds();
    expect($this->app['cache']->store()->has('minimailer:webhook:delivery_route'))->toBeTrue();
    $this->travel(2)->seconds();
    expect($this->app['cache']->store()->has('minimailer:webhook:delivery_route'))->toBeFalse();
})->with([0, -1, '123', 1.5, null, [[]]]);

it('normalizes middleware config', function (mixed $middleware, array $expected): void {
    TestCase::$webhookConfig = ['path' => 'hooks/mm', 'middleware' => $middleware];
    $this->refreshApplication();
    expect($this->app['router']->getRoutes()->getByName('minimailer.webhook')->middleware())->toBe([...$expected, VerifyMinimailerWebhook::class]);
})->with([
    ['api', ['api']], [null, ['api']], [42, ['api']],
    [['api', 42], ['api']], [[], []], ['web', ['web']],
]);

it('does not register routes when routes are cached', function (): void {
    $app = Mockery::mock(Application::class)->makePartial();
    $app->shouldReceive('routesAreCached')->once()->andReturn(true);
    $route = $this->app['router']->getRoutes()->getByName('minimailer.webhook');
    (new WebhooksServiceProvider($app))->boot();
    expect($this->app['router']->getRoutes()->getByName('minimailer.webhook'))->toBe($route);
});
