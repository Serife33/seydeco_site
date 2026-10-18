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
        $chairs = $this->chairCount($lines);
        $seats = $discount = 0;
        foreach ($lines as ['product' => $product, 'quantity' => $quantity]) {
            if (PricingType::Tables === $product->getCategory()?->getPricingType()) {
                $seats += ($product->getSeats() ?? 0) * $quantity;
            }
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

    /**
     * Chiffrage complet d'une demande (story 3.4) : lignes typées, total, acompte et reste, en centimes.
     * Une ligne sous son minimum n'est pas chiffrée : elle donne une raison `min_qty`. Aucun péage (ligne `toll` saisie en Epic 4).
     * Lignes produit et raisons `min_qty` portent `line`, la position de la ligne reçue.
     *
     * @param list<array{product: Product, quantity: int}> $lines
     *
     * @return array{lines: list<array<string, mixed>>, total: int, deposit: int, balance: int, excludesDelivery: bool, reasons: list<array{type: string, message: string}>}
     */
    public function quote(array $lines, PricingContext $context): array
    {
        $out = $reasons = $priced = [];
        foreach ($lines as $index => $line) {
            ['product' => $product, 'quantity' => $quantity] = $line;
            try {
                $unit = $this->unitPrice($product, $quantity);
            } catch (QuantityBelowMinimumException $e) {
                $reasons[] = ['type' => 'min_qty', 'productId' => $product->getId(), 'line' => $index, 'message' => \sprintf('Minimum %d pour « %s » : vous en avez %d.', $e->minQty, $product->getName(), $quantity)];
                continue;
            }
            $priced[] = $line;
            $out[] = ['type' => 'product', 'label' => (string) $product->getName(), 'productId' => $product->getId(), 'line' => $index, 'quantity' => $quantity, 'unitPrice' => $unit, 'amount' => $unit * $quantity];
        }
        if ($discount = $this->tablesWithChairsDiscount($priced)) {
            $out[] = $discount;
        }
        $chairs = $this->chairCount($priced);

        $excludesDelivery = false;
        if ($context->delivery) {
            // Mètres arrondis une seule fois au km supérieur ; au moins un voyage
            $km = null === $context->distanceMeters ? null : intdiv($context->distanceMeters + 999, 1000);
            $trips = null === $context->truckCapacity ? null : max(1, intdiv($chairs + $context->truckCapacity - 1, $context->truckCapacity));
            $excludesDelivery = null === $km || null === $trips || null === $context->pricePerKm;
            foreach ($context->pickup ? ['delivery' => 'Livraison', 'pickup' => 'Récupération'] : ['delivery' => 'Livraison'] as $type => $label) {
                // Aller-retour du camion pour chaque trajet : × 2 par livraison et par récupération
                $out[] = $excludesDelivery
                    ? ['type' => $type, 'label' => $label.' : à confirmer par Serife', 'amount' => 0]
                    : ['type' => $type, 'label' => $label, 'km' => $km, 'trips' => $trips, 'amount' => $km * 2 * $trips * $context->pricePerKm];
            }
            if (null !== $context->minChairs && $chairs < $context->minChairs) {
                $reasons[] = ['type' => 'delivery_min_chairs', 'message' => \sprintf('Livraison dès %d chaises : vous en avez %d.', $context->minChairs, $chairs)];
            }
            // Distance inconnue : réputée dans la zone, Serife confirme
            if (null !== $km && null !== $context->maxKm && $km > $context->maxKm) {
                $reasons[] = ['type' => 'delivery_max_km', 'message' => \sprintf('Livraison jusqu\'à %d km : votre lieu est à %d km.', $context->maxKm, $km)];
            }
        }

        if ($context->installation && !$context->delivery) {
            $reasons[] = ['type' => 'installation_pickup', 'message' => 'L\'installation est proposée seulement en livraison.'];
        } elseif ($context->installation) {
            $amount = 0;
            $onRequest = false;
            foreach ($priced as ['product' => $product, 'quantity' => $quantity]) {
                $categoryId = $product->getCategory()?->getId();
                $price = null === $categoryId ? null : $context->installPrices[$categoryId] ?? null;
                null === $price ? $onRequest = true : $amount += $price * $quantity;
            }
            if ($amount > 0) {
                $out[] = ['type' => 'installation', 'label' => 'Installation', 'amount' => $amount];
            }
            if ($onRequest) {
                $out[] = ['type' => 'installation', 'label' => 'Installation : tarif sur demande', 'amount' => 0];
            }
        }

        if ($context->cleaning && null !== $context->cleaningFee && $chairs > 0) {
            $out[] = ['type' => 'cleaning', 'label' => 'Chaises rendues sans nettoyage', 'quantity' => $chairs, 'unitPrice' => $context->cleaningFee, 'amount' => $chairs * $context->cleaningFee];
        }

        $total = array_sum(array_column($out, 'amount'));
        // Acompte arrondi au centime le plus proche, une seule fois, ici
        $deposit = intdiv($total * $context->depositBasisPoints + 5000, 10000);

        return ['lines' => $out, 'total' => $total, 'deposit' => $deposit, 'balance' => $total - $deposit, 'excludesDelivery' => $excludesDelivery, 'reasons' => $reasons];
    }

    /**
     * Tables suggérées (story 3.7) : chaises ÷ places de la première table à places de la sélection, arrondi au supérieur.
     * `missing` = places encore à garnir ÷ places de cette table. Null sans chaise, sans table à places ou si les tables suffisent.
     *
     * @param list<array{product: Product, quantity: int}> $lines
     *
     * @return array{line: int, seats: int, chairs: int, needed: int, missing: int}|null `line` = position de la table dans `$lines`
     */
    public function suggestedTables(array $lines): ?array
    {
        $table = null;
        $covered = 0;
        foreach ($lines as $index => ['product' => $product, 'quantity' => $quantity]) {
            $seats = PricingType::Tables === $product->getCategory()?->getPricingType() ? ($product->getSeats() ?? 0) : 0;
            if ($seats > 0) {
                $table ??= ['line' => $index, 'seats' => $seats];
                $covered += $seats * $quantity;
            }
        }
        $chairs = $this->chairCount($lines);
        if (null === $table || 0 === $chairs || $covered >= $chairs) {
            return null;
        }
        $ceil = fn (int $places) => intdiv($places + $table['seats'] - 1, $table['seats']);

        return $table + ['chairs' => $chairs, 'needed' => $ceil($chairs), 'missing' => $ceil($chairs - $covered)];
    }

    /** Chaises de la demande : quantités des produits de catégorie Chaises (même règle que la remise, story 1.12). */
    private function chairCount(array $lines): int
    {
        $chairs = 0;
        foreach ($lines as ['product' => $product, 'quantity' => $quantity]) {
            if (PricingType::Chairs === $product->getCategory()?->getPricingType()) {
                $chairs += $quantity;
            }
        }

        return $chairs;
    }
}
