<?php

declare(strict_types=1);

namespace App\Payments\Enums;

enum PaymentProvider: string
{
    case Stripe = 'stripe';
    case Pin = 'pin';
}
