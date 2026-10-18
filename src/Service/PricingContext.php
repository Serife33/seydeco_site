<?php

namespace App\Service;

/**
 * Tout ce dont le calcul a besoin hors produits (story 3.4, AD-9). Construit par PricingContextFactory depuis les Réglages ;
 * plus tard copié tel quel dans chaque devis pour qu'un réglage modifié ne le change jamais. Montants en centimes, null = non renseigné.
 */
final readonly class PricingContext
{
    /** @param array<int, int|null> $installPrices tarif d'installation par id de catégorie (null = tarif sur demande) */
    public function __construct(
        public int $depositBasisPoints = 0,
        public ?int $pricePerKm = null,
        public ?int $minChairs = null,
        public ?int $maxKm = null,
        public ?int $truckCapacity = null,
        public ?int $distanceMeters = null,
        public bool $delivery = false,
        public bool $pickup = false,
        public bool $installation = false,
        public bool $cleaning = false,
        public ?int $cleaningFee = null,
        public array $installPrices = [],
        public ?int $depositAmount = null,
    ) {
    }
}
