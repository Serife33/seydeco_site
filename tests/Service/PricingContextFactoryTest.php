<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\Setting;
use App\Repository\CategoryRepository;
use App\Service\PricingContextFactory;
use App\Service\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/** Story 3.4 : le contexte de calcul vient des Réglages, et de nulle part ailleurs. */
final class PricingContextFactoryTest extends TestCase
{
    private function factory(array $settings): PricingContextFactory
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn(array_map(fn ($name, $value) => new Setting($name, $value), array_keys($settings), $settings));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $category = new Category();
        (new \ReflectionProperty(Category::class, 'id'))->setValue($category, 4);
        $category->setInstallPrice(300);
        $categories = $this->createStub(CategoryRepository::class);
        $categories->method('findAll')->willReturn([$category]);

        return new PricingContextFactory(new SettingsProvider($em), $categories);
    }

    public function testReadsSettingsAndConvertsDepositToBasisPoints(): void
    {
        $context = $this->factory([
            SettingsProvider::DEPOSIT_PERCENT => '50',
            SettingsProvider::DELIVERY_PRICE_PER_KM => '100',
            SettingsProvider::DELIVERY_MIN_CHAIRS => '80',
            SettingsProvider::DELIVERY_MAX_KM => '70',
            SettingsProvider::TRUCK_CAPACITY => '170',
            SettingsProvider::CLEANING_FEE => '50',
            SettingsProvider::DEPOSIT_AMOUNT => '15000',
        ])->create(10_400, delivery: true, pickup: true, installation: true, cleaning: true);

        self::assertSame([5000, 100, 80, 70, 170, 10_400, 50, 15000], [$context->depositBasisPoints, $context->pricePerKm, $context->minChairs, $context->maxKm, $context->truckCapacity, $context->distanceMeters, $context->cleaningFee, $context->depositAmount]);
        self::assertTrue($context->delivery && $context->pickup && $context->installation && $context->cleaning);
        self::assertSame([4 => 300], $context->installPrices);
    }

    public function testEmptyOrUnreadableSettingsAreUnknown(): void
    {
        $context = $this->factory([SettingsProvider::DELIVERY_PRICE_PER_KM => '1 €', SettingsProvider::TRUCK_CAPACITY => '0'])->create(pickup: true);

        self::assertSame(0, $context->depositBasisPoints);
        self::assertNull($context->pricePerKm);
        self::assertNull($context->truckCapacity);
        self::assertNull($context->cleaningFee);
        // Récupération seulement avec une livraison
        self::assertFalse($context->pickup);
    }
}
