<?php

namespace App\Service;

use App\Entity\PriceTier;
use App\Entity\PricingType;
use App\Entity\Product;
use App\Exception\QuantityBelowMinimumException;

/** Seul endroit où se calcule un prix (centimes). Le navigateur ne calcule rien. */
final class PricingCalculator
{
    /** Prix unitaire du palier atteint par `$quantity`. */
    public function unitPrice(Product $product, int $quantity): int
    {
        $minQty = $product->getMinQty() ?? 1;
        if ($quantity < $minQty) {
            throw new QuantityBelowMinimumException($quantity, $minQty);
        }
        $reached = null;
        foreach ($product->getPriceTiers() as $tier) {
            if ($tier->getMinQty() <= $quantity && $tier->getMinQty() > ($reached?->getMinQty() ?? 0)) {
                $reached = $tier;
            }
        }

        return $reached?->getPrice() ?? throw new \LogicException(\sprintf('Aucun palier de prix pour %d unités du produit %s.', $quantity, $product->getName()));
    }

    /** Prix « dès » : le plus bas des paliers ; null sans palier. */
    public function fromPrice(Product $product): ?int
    {
        $prices = $product->getPriceTiers()->map(fn (PriceTier $t) => $t->getPrice())->getValues();

        return [] === $prices ? null : min($prices);
    }

    /**
     * Remise tables avec chaises, tout ou rien : si les chaises garnissent toutes les tables (Σ places × quantité),
     * chaque table ayant un prix avec chaises est ramenée à ce prix. Sinon, ou sans rien à remettre : null.
     *
     * @param list<array{product: Product, quantity: int}> $lines
     *
     * @return array{type: 'discount', label: string, amount: int}|null montant négatif, en centimes
     */
    public function tablesWithChairsDiscount(array $lines): ?array
    {
        $chairs = $seats = $discount = 0;
        foreach ($lines as ['product' => $product, 'quantity' => $quantity]) {
            match ($product->getCategory()?->getPricingType()) {
                PricingType::Chairs => $chairs += $quantity,
                PricingType::Tables => $seats += ($product->getSeats() ?? 0) * $quantity,
                default => null,
            };
            if (PricingType::Tables === $product->getCategory()?->getPricingType() && null !== $product->getPriceWithChairs()) {
                // Un palier déjà plus bas que le prix avec chaises n'est jamais augmenté
                $discount += max(0, $this->unitPrice($product, $quantity) - $product->getPriceWithChairs()) * $quantity;
            }
        }

        // Sans aucune chaise, pas de remise (même pour des tables dont le nombre de places n'est pas renseigné)
        if (0 === $discount || 0 === $chairs || $chairs < $seats) {
            return null;
        }

        return ['type' => 'discount', 'label' => 'Remise tables avec chaises', 'amount' => -$discount];
    }
}
