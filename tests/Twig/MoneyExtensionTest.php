<?php

namespace App\Tests\Twig;

use App\Service\PricingCalculator;
use App\Twig\MoneyExtension;
use PHPUnit\Framework\TestCase;

final class MoneyExtensionTest extends TestCase
{
    public function testMoneyFormat(): void
    {
        $money = new MoneyExtension(new PricingCalculator());
        self::assertSame("1\u{A0}234,50\u{A0}€", $money->money(123450));
        self::assertSame("0,05\u{A0}€", $money->money(5));
        self::assertSame("1\u{A0}000\u{A0}000,00\u{A0}€", $money->money(100000000));
        self::assertSame("1\u{A0}€", $money->money(100, short: true));
        self::assertSame("1,50\u{A0}€", $money->money(150, short: true));
    }
}
