<?php

namespace App\Service;

use App\Repository\CategoryRepository;

/** Seul endroit qui lit les Réglages pour le calcul (story 3.4) : PricingCalculator n'appelle jamais SettingsProvider. */
final class PricingContextFactory
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly CategoryRepository $categories,
    ) {
    }

    /** Sans livraison (`$delivery` faux) : retrait au dépôt, ou mode pas encore choisi. */
    public function create(?int $distanceMeters = null, bool $delivery = false, bool $pickup = false, bool $installation = false, bool $cleaning = false): PricingContext
    {
        $installPrices = [];
        foreach ($this->categories->findAll() as $category) {
            $installPrices[$category->getId()] = $category->getInstallPrice();
        }

        return new PricingContext(
            // Acompte réglé en % (« 50 ») : converti ici en points de base
            depositBasisPoints: ($this->int(SettingsProvider::DEPOSIT_PERCENT) ?? 0) * 100,
            pricePerKm: $this->int(SettingsProvider::DELIVERY_PRICE_PER_KM),
            minChairs: $this->int(SettingsProvider::DELIVERY_MIN_CHAIRS),
            maxKm: $this->int(SettingsProvider::DELIVERY_MAX_KM),
            truckCapacity: $this->int(SettingsProvider::TRUCK_CAPACITY) ?: null,
            distanceMeters: $distanceMeters,
            delivery: $delivery,
            pickup: $delivery && $pickup,
            installation: $installation,
            cleaning: $cleaning,
            cleaningFee: $this->int(SettingsProvider::CLEANING_FEE),
            installPrices: $installPrices,
            depositAmount: $this->int(SettingsProvider::DEPOSIT_AMOUNT),
        );
    }

    /** Vide ou illisible (saisi à la main en base) = non renseigné. */
    private function int(string $key): ?int
    {
        $value = trim($this->settings->get($key));

        return ctype_digit($value) ? (int) $value : null;
    }
}
