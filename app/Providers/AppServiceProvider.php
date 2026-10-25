<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Merchant;
use App\Payments\PaymentGatewayResolver;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Exceptions\PaymentGatewayUnavailable;
use App\Payments\Gateways\StripePaymentGateway;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
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
            function (Application $app) {
                return new PaymentGatewayResolver([
                    'stripe' => function (
                        Merchant $merchant,
                    ) use ($app): PaymentGateway {
                        $secretKey = $merchant->psp_credentials['secret_key'] ?? null;
                        $apiVersion = $app['config']->get('services.stripe.api_version');

                        if (
                            ! is_string($secretKey)
                            || ! is_string($apiVersion)
                            || $apiVersion === ''
                        ) {
                            throw new PaymentGatewayUnavailable(
                                'Stripe configuration is incomplete.',
                            );
                        }

                        return new StripePaymentGateway(
                            http: $app->make(Factory::class),
                            secretKey: $secretKey,
                            merchantScope: (string) $merchant->getKey(),
                            apiVersion: $apiVersion,
                        );
                    },
                ]);
            }
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
