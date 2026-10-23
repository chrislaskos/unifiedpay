<?php

declare(strict_types=1);

namespace App\Payments;

use InvalidArgumentException;

final class ChargeResult
{
    public function __construct(
        public readonly string $providerChargeId,
        public readonly Money $money,
    ) {
        if (trim($providerChargeId) === '') {
            throw new InvalidArgumentException(
                'A provider charge ID is required.',
            );
        }
    }
}
