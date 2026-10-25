<?php

declare(strict_types=1);

namespace App\Payments\Enums;

enum PaymentFailureReason: int
{
    case PaymentDeclined = 0;
    case AuthenticationRequired = 1;
    case PaymentRequestRejected = 2;
    case ProviderUnavailable = 3;
    case OutcomeUnknown = 4;
    case IdempotencyConflict = 5;

    public function httpStatus(): int
    {
        return match ($this) {
            self::PaymentDeclined => 402,
            self::AuthenticationRequired => 422,
            self::PaymentRequestRejected, self::OutcomeUnknown => 502,
            self::ProviderUnavailable => 503,
            self::IdempotencyConflict => 409,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::PaymentDeclined => 'The payment was declined.',
            self::AuthenticationRequired => 'The payment requires customer authentication, which this API does not yet support.',
            self::PaymentRequestRejected => 'The provider rejected the payment request.',
            self::ProviderUnavailable => 'The payment provider is unavailable or incorrectly configured.',
            self::OutcomeUnknown => 'The payment outcome could not be confirmed. Do not submit a new payment attempt.',
            self::IdempotencyConflict => 'The idempotency key conflicts with an existing or ongoing request.',
        };
    }
}
