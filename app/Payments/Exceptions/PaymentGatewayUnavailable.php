<?php

declare(strict_types=1);

namespace App\Payments\Exceptions;

use RuntimeException;

final class PaymentGatewayUnavailable extends RuntimeException
{
}
