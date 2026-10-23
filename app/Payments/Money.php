<?php

declare(strict_types=1);

namespace App\Payments;

use InvalidArgumentException;

final class Money
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                'Amount must be greater than zero.',
            );
        }

        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException(
                'Currency must be a three-letter uppercase code.',
            );
        }
    }
}
