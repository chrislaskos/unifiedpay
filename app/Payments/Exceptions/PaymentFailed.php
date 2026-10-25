<?php

declare(strict_types=1);

namespace App\Payments\Exceptions;

use App\Payments\Enums\PaymentFailureReason;
use RuntimeException;

final class PaymentFailed extends RuntimeException
{
    public function __construct(
        public readonly PaymentFailureReason $reason,
    ) {
        parent::__construct($reason->message(), $reason->value);
    }
}
