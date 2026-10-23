<?php

declare(strict_types=1);

namespace App\Payments;

final class CardDetails
{
    public function __construct(
        public readonly string $number,
        public readonly int $expirationMonth,
        public readonly int $expirationYear,
        public readonly string $cvv,
        public readonly string $cardholderName,
    ) {
    }

    public function __debugInfo(): array
    {
        return [
            'card' => '[REDACTED]',
        ];
    }
}
