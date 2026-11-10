<?php

declare(strict_types=1);

namespace MiniMailer\Laravel\Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Testing\TestResponse;
use MiniMailer\Laravel\MinimailerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @var array<string, mixed>|null */
    public static ?array $webhookConfig = [];

    /** @return list<class-string> */
    protected function getPackageProviders(mixed $app): array
    {
        return [MinimailerServiceProvider::class];
    }

    protected function defineEnvironment(mixed $app): void
    {
        if (self::$webhookConfig === null) {
            $app['config']->set('minimailer', []);
        } else {
            $app['config']->set('minimailer.webhooks', self::$webhookConfig);
        }
        $app['config']->set('minimailer.webhook_secret', 'whsec_'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.webhooks', ['driver' => 'array']);
    }

    /**
     * Boots a fresh application with the given `minimailer.webhooks` group, or none at all for null.
     *
     * @param  array<string, mixed>|null  $config
     */
    protected function bootWithWebhookConfig(?array $config): void
    {
        self::$webhookConfig = $config;
        $this->refreshApplication();
    }

    protected function webhookRoute(): ?Route
    {
        return $this->app['router']->getRoutes()->getByName('minimailer.webhook');
    }

    /** @return array<string, string> */
    protected function signedWebhookHeaders(string $body, string $id = 'delivery_route'): array
    {
        $timestamp = time();
        $key = (string) base64_decode(substr(config('minimailer.webhook_secret'), 6), true);

        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_WEBHOOK_ID' => $id,
            'HTTP_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            'HTTP_WEBHOOK_SIGNATURE' => 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $key, true)),
        ];
    }

    /** @param  array<string, string>  $headers  overrides for the signed headers */
    protected function postWebhook(string $body, array $headers = []): TestResponse
    {
        return $this->call('POST', '/hooks/mm', server: [...$this->signedWebhookHeaders($body), ...$headers], content: $body);
    }

    protected function hasDedupeKey(?string $store = null): bool
    {
        return $this->app['cache']->store($store)->has('minimailer:webhook:delivery_route');
    }
}
