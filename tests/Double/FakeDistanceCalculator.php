<?php

namespace App\Tests\Double;

use App\Service\DistanceCalculatorInterface;

/** Distance simulée en test (aucun appel Google) : `$meters` fixé par le test, null = adresse introuvable. */
final class FakeDistanceCalculator implements DistanceCalculatorInterface
{
    public static ?int $meters = 10_400;
    public static int $calls = 0;

    public static function reset(): void
    {
        self::$meters = 10_400;
        self::$calls = 0;
    }

    public function metersFromDepot(string $address): ?int
    {
        ++self::$calls;

        return self::$meters;
    }
}
