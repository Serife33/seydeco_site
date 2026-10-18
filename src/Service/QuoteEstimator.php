<?php

namespace App\Service;

use App\Entity\Product;
use App\Entity\ProductColor;
use App\Repository\ProductRepository;

/**
 * Lecture d'une demande envoyée par le navigateur et chiffrage serveur (AD-27) : sert à POST /estimation et à l'envoi
 * de la demande (story 3.12), pour que les deux recalculent tout de la même façon, distance comprise.
 * JSON : {lines: [{productId, quantity, colorId?}], mode: pickup|delivery, address, options: {pickup, installation, cleaning}}.
 */
final class QuoteEstimator
{
    private const int MAX_LINES = 100;
    private const int MAX_QTY = 100_000;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly PricingCalculator $calculator,
        private readonly PricingContextFactory $contexts,
        private readonly DistanceCalculatorInterface $distance,
    ) {
    }

    /**
     * Produits masqués, inconnus ou sans palier : ignorés et listés dans `ignored` (position dans la liste reçue).
     *
     * @param array<string, mixed> $data
     *
     * @return array{priced: list<array{product: Product, quantity: int, color: ?ProductColor}>, positions: list<int>, ignored: list<array{line: int, productId: int}>, delivery: bool, collect: bool, address: string, meters: ?int, context: PricingContext, quote: array<string, mixed>, chairs: int}
     *
     * @throws \InvalidArgumentException demande illisible, message en mots simples
     */
    public function estimate(array $data): array
    {
        $raw = $data['lines'] ?? [];
        if (!\is_array($raw) || !array_is_list($raw) || \count($raw) > self::MAX_LINES) {
            throw new \InvalidArgumentException('Demande illisible.');
        }
        foreach ($raw as $line) {
            if (!\is_array($line) || !\is_int($line['productId'] ?? null) || $line['productId'] < 1) {
                throw new \InvalidArgumentException('Un produit de la demande est illisible.');
            }
            $quantity = $line['quantity'] ?? null;
            if (!\is_int($quantity) || $quantity < 1 || $quantity > self::MAX_QTY) {
                throw new \InvalidArgumentException('Chaque quantité doit être un nombre entier, sans virgule, d\'au moins 1.');
            }
        }

        $products = [];
        foreach ($raw ? $this->products->findBy(['id' => array_unique(array_column($raw, 'productId'))]) : [] as $product) {
            $products[$product->getId()] = $product;
        }
        $priced = $positions = $ignored = [];
        foreach ($raw as $i => $line) {
            $product = $products[$line['productId']] ?? null;
            if (!$product || !$product->isVisible() || !$product->getCategory()?->isVisible() || null === $this->calculator->fromPrice($product)) {
                $ignored[] = ['line' => $i, 'productId' => $line['productId']];
                continue;
            }
            // Coloris d'un autre produit ou inconnu : ignoré
            $color = null;
            foreach ($product->getColors() as $candidate) {
                if ($candidate->getId() === ($line['colorId'] ?? null)) {
                    $color = $candidate;
                }
            }
            $positions[] = $i;
            $priced[] = ['product' => $product, 'quantity' => $line['quantity'], 'color' => $color];
        }

        $delivery = 'delivery' === ($data['mode'] ?? null);
        $options = \is_array($data['options'] ?? null) ? $data['options'] : [];
        $address = \is_string($data['address'] ?? null) ? mb_substr(trim($data['address']), 0, 255) : '';
        $meters = $delivery && '' !== $address ? $this->distance->metersFromDepot($address) : null;
        $collect = $delivery && true === ($options['pickup'] ?? null);
        $context = $this->contexts->create($meters, $delivery, $collect, true === ($options['installation'] ?? null), true === ($options['cleaning'] ?? null));

        return [
            'priced' => $priced,
            'positions' => $positions,
            'ignored' => $ignored,
            'delivery' => $delivery,
            'collect' => $collect,
            'address' => $address,
            'meters' => $meters,
            'context' => $context,
            'quote' => $this->calculator->quote($priced, $context),
            'chairs' => $this->calculator->chairCount($priced),
        ];
    }
}
