<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\PriceTier;
use App\Entity\PricingType;
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

    // --- Remise tables avec chaises (story 1.12) ---

    private function item(PricingType $type, int $price, ?int $seats = null, ?int $priceWithChairs = null): Product
    {
        $category = new Category();
        $category->setPricingType($type);
        $product = new Product();
        $product->setCategory($category);
        $product->setSeats($seats);
        $product->setPriceWithChairs($priceWithChairs);
        $product->addPriceTier(new PriceTier(1, $price));

        return $product;
    }

    /** @return array{type: string, label: string, amount: int}|null */
    private function discount(array $lines): ?array
    {
        return (new PricingCalculator())->tablesWithChairsDiscount(array_map(fn ($l) => ['product' => $l[0], 'quantity' => $l[1]], $lines));
    }

    public function testRoundTablesWithEnoughChairsGetDiscount(): void
    {
        $round = $this->item(PricingType::Tables, 1500, 8, 1200);
        $chair = $this->item(PricingType::Chairs, 250);

        self::assertSame(
            ['type' => 'discount', 'label' => 'Remise tables avec chaises', 'amount' => -4500],
            $this->discount([[$round, 15], [$chair, 120]]),
        );
    }

    public function testNotEnoughChairsMeansNoDiscount(): void
    {
        $round = $this->item(PricingType::Tables, 1500, 8, 1200);
        self::assertNull($this->discount([[$round, 15], [$this->item(PricingType::Chairs, 250), 100]]));
    }

    public function testTablesAloneOrChairsAloneHaveNoDiscount(): void
    {
        self::assertNull($this->discount([[$this->item(PricingType::Tables, 1500, 8, 1200), 15]]));
        self::assertNull($this->discount([[$this->item(PricingType::Tables, 1500, null, 1200), 2]]));
        self::assertNull($this->discount([[$this->item(PricingType::Chairs, 250), 120]]));
        self::assertNull($this->discount([]));
    }

    public function testRectangularTablesCountSeatsButGetNoDiscount(): void
    {
        $round = $this->item(PricingType::Tables, 1500, 8, 1200);
        $rectangular = $this->item(PricingType::Tables, 2000, 10);
        $chair = $this->item(PricingType::Chairs, 250);

        // 15 × 8 + 5 × 10 = 170 places : toutes les tables doivent être garnies
        self::assertNull($this->discount([[$round, 15], [$rectangular, 5], [$chair, 169]]));
        self::assertSame(-4500, $this->discount([[$round, 15], [$rectangular, 5], [$chair, 170]])['amount']);
        self::assertNull($this->discount([[$rectangular, 5], [$chair, 200]]));
    }

    public function testOtherCategoriesAreIgnored(): void
    {
        $round = $this->item(PricingType::Tables, 1500, 8, 1200);
        $other = $this->item(PricingType::Other, 500, 8, 100);
        self::assertSame(-300, $this->discount([[$round, 1], [$other, 3], [$this->item(PricingType::Chairs, 250), 8]])['amount']);
    }
}
