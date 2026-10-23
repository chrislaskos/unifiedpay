<?php

declare(strict_types=1);

namespace App\Providers;

use App\Payments\PaymentGatewayResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            PaymentGatewayResolver::class,
            fn () => new PaymentGatewayResolver(
                factories: [],
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
