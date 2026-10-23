<?php

declare(strict_types=1);

namespace App\Payments\Contracts;

use App\Payments\ChargeRequest;
use App\Payments\ChargeResult;

interface PaymentGateway
{
    public function charge(ChargeRequest $request): ChargeResult;
}
