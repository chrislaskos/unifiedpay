<?php

declare(strict_types=1);

namespace App\Payments;

use InvalidArgumentException;

final class ChargeRequest
{
    public function __construct(
        public readonly Money $money,
        public readonly CardDetails $card,
        public readonly string $idempotencyKey,
    ) {
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException(
                'An idempotency key is required.',
            );
        }
    }
}
