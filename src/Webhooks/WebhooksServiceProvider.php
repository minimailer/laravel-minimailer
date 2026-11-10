<?php

declare(strict_types=1);

namespace MiniMailer\Laravel\Webhooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MiniMailer\Laravel\Http\Middleware\VerifyMinimailerWebhook;

final class WebhooksServiceProvider extends ServiceProvider
{
    private const DEFAULT_DEDUPE_TTL = 259200; // three days

    public function register(): void
    {
        $this->app->bind(WebhookController::class, function (): WebhookController {
            $ttl = config('minimailer.webhooks.dedupe_ttl', self::DEFAULT_DEDUPE_TTL);
            $store = config('minimailer.webhooks.cache_store');

            return new WebhookController(
                $this->app->make('cache')->store(is_string($store) ? $store : null),
                $this->app->make(Dispatcher::class),
                is_int($ttl) && $ttl > 0 ? $ttl : self::DEFAULT_DEDUPE_TTL,
            );
        });
    }

    public function boot(): void
    {
        $path = config('minimailer.webhooks.path');

        if ($this->app->routesAreCached() || ! is_string($path) || trim($path) === '') {
            return;
        }

        Route::post(trim($path), WebhookController::class)
            ->middleware([...$this->middleware(), VerifyMinimailerWebhook::class])
            ->name('minimailer.webhook');
    }

    /** @return list<string> */
    private function middleware(): array
    {
        $middleware = config('minimailer.webhooks.middleware', ['api']);

        if (is_string($middleware)) {
            return [$middleware];
        }

        if (! is_array($middleware)) {
            return ['api'];
        }

        return array_values(array_filter($middleware, is_string(...)));
    }
}
