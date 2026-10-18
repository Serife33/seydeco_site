<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\PriceTier;
use App\Entity\PricingType;
use App\Entity\Product;
use App\Service\PricingCalculator;
use App\Service\PricingContext;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/** Story 3.4 : chiffrage complet (FR-3, AD-5, AD-9, AD-10). Tarif 1 €/km, camion de 170 chaises sauf mention. */
final class PricingQuoteTest extends TestCase
{
    private function product(PricingType $type, int $price, ?int $categoryId = null, ?int $minQty = null, ?int $seats = null, ?int $priceWithChairs = null): Product
    {
        $category = new Category();
        $category->setPricingType($type);
        (new \ReflectionProperty(Category::class, 'id'))->setValue($category, $categoryId);
        $product = new Product();
        $product->setName($type->name);
        $product->setCategory($category);
        $product->setMinQty($minQty);
        $product->setSeats($seats);
        $product->setPriceWithChairs($priceWithChairs);
        $product->addPriceTier(new PriceTier($minQty ?? 1, $price));

        return $product;
    }

    private function chairs(int $quantity): array
    {
        return ['product' => $this->product(PricingType::Chairs, 250, 1), 'quantity' => $quantity];
    }

    private function delivery(?int $meters, bool $pickup = true, array $extra = []): PricingContext
    {
        return new PricingContext(...$extra + ['depositBasisPoints' => 5000, 'pricePerKm' => 100, 'minChairs' => 80, 'maxKm' => 70, 'truckCapacity' => 170, 'distanceMeters' => $meters, 'delivery' => true, 'pickup' => $pickup]);
    }

    /** @return array<string, int> montant par type de ligne (hors produits) */
    private function amounts(array $quote): array
    {
        $amounts = [];
        foreach ($quote['lines'] as $line) {
            if ('product' !== $line['type']) {
                $amounts[$line['type']] = ($amounts[$line['type']] ?? 0) + $line['amount'];
            }
        }

        return $amounts;
    }

    private function reasons(array $quote): array
    {
        return array_column($quote['reasons'], 'message', 'type');
    }

    public function testDeliveryAndPickupFor200ChairsAt10Km(): void
    {
        $quote = (new PricingCalculator())->quote([$this->chairs(200)], $this->delivery(10_000));

        // 10 km × 4 × 2 voyages × 1 € = 80 €, en deux lignes
        self::assertSame(['delivery' => 4000, 'pickup' => 4000], $this->amounts($quote));
        self::assertSame(2, $quote['lines'][1]['trips']);
        self::assertSame(50000 + 8000, $quote['total']);
        self::assertFalse($quote['excludesDelivery']);
        self::assertSame([], $quote['reasons']);
    }

    #[TestWith([170, 1])]
    #[TestWith([171, 2])]
    public function testTripsFollowTruckCapacity(int $chairs, int $trips): void
    {
        $quote = (new PricingCalculator())->quote([$this->chairs($chairs)], $this->delivery(10_000));
        self::assertSame($trips, $quote['lines'][1]['trips']);
        self::assertSame(['delivery' => 2000 * $trips, 'pickup' => 2000 * $trips], $this->amounts($quote));
    }

    public function testMetersAreRoundedUpOnceAndDeliveryAloneIsTimesTwo(): void
    {
        $quote = (new PricingCalculator())->quote([$this->chairs(100)], $this->delivery(10_400, pickup: false));
        self::assertSame(11, $quote['lines'][1]['km']);
        self::assertSame(['delivery' => 2200], $this->amounts($quote));
    }

    public function testUnknownDistanceIsToBeConfirmedAndEligible(): void
    {
        $quote = (new PricingCalculator())->quote([$this->chairs(100)], $this->delivery(null));
        self::assertSame(['delivery' => 0, 'pickup' => 0], $this->amounts($quote));
        self::assertSame(['Livraison : à confirmer par Serife', 'Récupération : à confirmer par Serife'], array_column(\array_slice($quote['lines'], 1), 'label'));
        self::assertTrue($quote['excludesDelivery']);
        self::assertSame([], $quote['reasons']);
    }

    public function testMinimumChairsForDelivery(): void
    {
        $calculator = new PricingCalculator();
        self::assertSame(['delivery_min_chairs' => 'Livraison dès 80 chaises : vous en avez 79.'], $this->reasons($calculator->quote([$this->chairs(79)], $this->delivery(10_000))));
        self::assertSame([], $calculator->quote([$this->chairs(80)], $this->delivery(10_000))['reasons']);
        // En retrait, pas de minimum
        self::assertSame([], $calculator->quote([$this->chairs(20)], new PricingContext(minChairs: 80))['reasons']);
    }

    public function testMaximumDistance(): void
    {
        $calculator = new PricingCalculator();
        self::assertSame([], $calculator->quote([$this->chairs(100)], $this->delivery(70_000))['reasons']);
        self::assertSame(['delivery_max_km' => 'Livraison jusqu\'à 70 km : votre lieu est à 71 km.'], $this->reasons($calculator->quote([$this->chairs(100)], $this->delivery(70_001))));
    }

    public function testInstallationWithAndWithoutPrice(): void
    {
        $arch = ['product' => $this->product(PricingType::Other, 5000, 2), 'quantity' => 2];
        $context = $this->delivery(10_000, extra: ['installation' => true, 'installPrices' => [1 => 30, 2 => null]]);
        $quote = (new PricingCalculator())->quote([$this->chairs(100), $arch], $context);

        $installation = array_values(array_filter($quote['lines'], fn ($l) => 'installation' === $l['type']));
        self::assertSame([['Installation', 3000], ['Installation : tarif sur demande', 0]], array_map(fn ($l) => [$l['label'], $l['amount']], $installation));
    }

    public function testInstallationIsRefusedForPickupAtDepot(): void
    {
        $quote = (new PricingCalculator())->quote([$this->chairs(100)], new PricingContext(installation: true, installPrices: [1 => 30]));
        self::assertSame([], $this->amounts($quote));
        self::assertSame(['installation_pickup' => 'L\'installation est proposée seulement en livraison.'], $this->reasons($quote));
    }

    public function testCleaningOptionOnlyWhenChosenAndPriced(): void
    {
        $calculator = new PricingCalculator();
        $quote = $calculator->quote([$this->chairs(100)], new PricingContext(cleaning: true, cleaningFee: 50));
        self::assertSame(['cleaning' => 5000], $this->amounts($quote));
        self::assertSame([], $this->amounts($calculator->quote([$this->chairs(100)], new PricingContext(cleaningFee: 50))));
        self::assertSame([], $this->amounts($calculator->quote([$this->chairs(100)], new PricingContext(cleaning: true))));
    }

    public function testDepositOnOddTotalIsRoundedOnce(): void
    {
        $item = ['product' => $this->product(PricingType::Other, 1001), 'quantity' => 1];
        $quote = (new PricingCalculator())->quote([$item], new PricingContext(depositBasisPoints: 5000));
        self::assertSame([1001, 501, 500], [$quote['total'], $quote['deposit'], $quote['balance']]);
    }

    public function testTablesWithChairsDiscountCombinedWithDelivery(): void
    {
        $round = ['product' => $this->product(PricingType::Tables, 1500, 3, seats: 8, priceWithChairs: 1200), 'quantity' => 15];
        $quote = (new PricingCalculator())->quote([$round, $this->chairs(120)], $this->delivery(10_000));

        self::assertSame(['discount' => -4500, 'delivery' => 2000, 'pickup' => 2000], $this->amounts($quote));
        self::assertSame(22500 + 30000 - 4500 + 4000, $quote['total']);
    }

    public function testQuantityBelowMinimumIsAReasonNotALine(): void
    {
        $chair = ['product' => $this->product(PricingType::Chairs, 250, 1, minQty: 15), 'quantity' => 10];
        $quote = (new PricingCalculator())->quote([$chair], new PricingContext());
        self::assertSame([], $quote['lines']);
        self::assertSame(['min_qty' => 'Minimum 15 pour « Chairs » : vous en avez 10.'], $this->reasons($quote));
        self::assertSame(0, $quote['total']);
    }
}
