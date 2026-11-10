<?php

declare(strict_types=1);

namespace MiniMailer\Laravel\Webhooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MiniMailer\Laravel\Http\Middleware\VerifyMinimailerWebhook;

final class WebhooksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WebhookController::class, function (): WebhookController {
            $ttl = config('minimailer.webhooks.dedupe_ttl', 259200);
            $store = config('minimailer.webhooks.cache_store');

            return new WebhookController(
                $this->app->make('cache')->store(is_string($store) ? $store : null),
                $this->app->make(Dispatcher::class),
                is_int($ttl) && $ttl > 0 ? $ttl : 259200,
            );
        });
    }

    public function boot(): void
    {
        $path = config('minimailer.webhooks.path');

        if ($this->app->routesAreCached() || ! is_string($path) || trim($path) === '') {
            return;
        }

        $middleware = config('minimailer.webhooks.middleware', ['api']);
        $middleware = is_string($middleware) ? [$middleware] : $middleware;
        $middleware = is_array($middleware) ? array_values(array_filter($middleware, is_string(...))) : ['api'];

        Route::post(trim($path), WebhookController::class)
            ->middleware([...$middleware, VerifyMinimailerWebhook::class])
            ->name('minimailer.webhook');
    }
}
