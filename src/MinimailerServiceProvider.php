<?php

declare(strict_types=1);

namespace MiniMailer\Laravel;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use MiniMailer\Client;
use MiniMailer\Contracts\ClientContract;
use MiniMailer\Contracts\MiniMailerContract;
use MiniMailer\MiniMailer;
use Override;

final class MinimailerServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/minimailer.php', 'minimailer');

        $this->app->singleton(ClientContract::class, fn (): ClientContract => new Client(
            (string) config('minimailer.key'),
            (string) config('minimailer.base_url', 'https://api.minimailer.app/'),
        ));

        $this->app->singleton(
            MiniMailerContract::class,
            fn (): MiniMailerContract => new MiniMailer($this->app->make(ClientContract::class)),
        );
    }

    #[Override]
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/minimailer.php' => $this->app->configPath('minimailer.php'),
        ], 'minimailer-config');

        Mail::extend('minimailer', function (array $config): MinimailerTransport {
            return new MinimailerTransport(
                $this->app->make(MiniMailerContract::class),
                (string) ($config['domain_id'] ?? config('minimailer.domain_id')),
                $this->app->make('log'),
            );
        });
    }
}
