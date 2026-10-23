<?php

declare(strict_types=1);

namespace App\Payments;

use App\Models\Merchant;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Exceptions\PaymentGatewayUnavailable;
use Closure;

final class PaymentGatewayResolver
{
    /**
     * @param array<string, Closure(Merchant): PaymentGateway> $factories
     */
    public function __construct(
        private readonly array $factories,
    ) {
    }

    public function resolve(Merchant $merchant): PaymentGateway
    {
        $factory = $this->factories[$merchant->psp->value] ?? null;

        if ($factory === null) {
            throw new PaymentGatewayUnavailable(
                'No gateway is configured for this payment provider.',
            );
        }

        return $factory($merchant);
    }
}
