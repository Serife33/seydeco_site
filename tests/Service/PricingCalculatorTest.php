<?php

namespace App\Tests\Service;

use App\Entity\PriceTier;
use App\Entity\Product;
use App\Exception\QuantityBelowMinimumException;
use App\Service\PricingCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PricingCalculatorTest extends TestCase
{
    private function chair(): Product
    {
        $product = new Product();
        $product->setMinQty(15);
        // Ordre volontairement mélangé : le calcul ne dépend pas de l'ordre en mémoire
        foreach ([[50, 220], [15, 250], [100, 190]] as [$qty, $price]) {
            $product->addPriceTier(new PriceTier($qty, $price));
        }

        return $product;
    }

    /** @return iterable<string, array{int, int}> */
    public static function tierEdges(): iterable
    {
        yield 'minimum' => [15, 250];
        yield 'juste avant 50' => [49, 250];
        yield 'pile 50' => [50, 220];
        yield 'juste avant 100' => [99, 220];
        yield 'pile 100' => [100, 190];
        yield 'au-delà' => [5000, 190];
    }

    #[DataProvider('tierEdges')]
    public function testUnitPriceAtTierEdges(int $quantity, int $expected): void
    {
        self::assertSame($expected, (new PricingCalculator())->unitPrice($this->chair(), $quantity));
    }

    public function testBelowMinimumIsRefused(): void
    {
        try {
            (new PricingCalculator())->unitPrice($this->chair(), 14);
            self::fail('Exception attendue');
        } catch (QuantityBelowMinimumException $e) {
            self::assertSame(14, $e->quantity);
            self::assertSame(15, $e->minQty);
        }
    }

    public function testFromPrice(): void
    {
        self::assertSame(190, (new PricingCalculator())->fromPrice($this->chair()));
        self::assertNull((new PricingCalculator())->fromPrice(new Product()));
    }
}
