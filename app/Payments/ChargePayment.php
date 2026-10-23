<?php

declare(strict_types=1);

namespace App\Payments;

use App\Models\Merchant;

final class ChargePayment
{
    public function __construct(
        private readonly PaymentGatewayResolver $gateways,
    ) {
    }

    public function execute(
        Merchant $merchant,
        ChargeRequest $request,
    ): ChargeResult {
        $gateway = $this->gateways->resolve($merchant);

        return $gateway->charge($request);
    }
}
