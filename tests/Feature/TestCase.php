<?php

declare(strict_types=1);

namespace MiniMailer\Laravel\Tests\Feature;

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
}
