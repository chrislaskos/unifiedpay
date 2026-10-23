<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Payments\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_preserves_amount_in_minor_units(): void
    {
        $money = new Money(1050, 'EUR');

        self::assertSame(1050, $money->amount);
        self::assertSame('EUR', $money->currency);
    }

    public function test_it_rejects_zero_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money(0, 'EUR');
    }

    public function test_it_rejects_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money(-100, 'EUR');
    }

    public function test_it_rejects_invalid_currency_format(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money(1050, 'EURO');
    }

    public function test_it_rejects_lowercase_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money(1050, 'eur');
    }
}
