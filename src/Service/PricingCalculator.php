<?php

namespace App\Service;

use App\Entity\PriceTier;
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

}
