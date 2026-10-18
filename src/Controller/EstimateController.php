<?php

namespace App\Controller;

use App\Repository\ProductRepository;
use App\Service\DistanceCalculatorInterface;
use App\Service\PricingCalculator;
use App\Service\PricingContextFactory;
use App\Service\SettingsProvider;
use App\Twig\MoneyExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Estimation de la sélection (story 3.6, AD-27) : seul PricingCalculator chiffre, le navigateur affiche.
 * JSON in : {lines: [{productId, quantity}], date, mode: pickup|delivery, address, options: {pickup, installation, cleaning}}.
 * Sans session ni cookie ; ~120 appels/min par IP.
 */
final class EstimateController extends AbstractController
{
    private const int MAX_LINES = 100;
    private const int MAX_QTY = 100_000;
    public const string DISTANCE_UNKNOWN = 'Adresse introuvable : les frais de livraison seront confirmés par Serife.';

    public function __construct(
        private readonly ProductRepository $products,
        private readonly SettingsProvider $settings,
        private readonly PricingCalculator $calculator,
        private readonly PricingContextFactory $contexts,
        private readonly DistanceCalculatorInterface $distance,
        private readonly MoneyExtension $money,
        private readonly RateLimiterFactoryInterface $estimateLimiter,
    ) {
    }

    #[Route('/estimation', name: 'estimate', methods: ['POST'], stateless: true)]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->estimateLimiter->create($request->getClientIp() ?? '')->consume()->isAccepted()) {
            return $this->error('Trop de demandes. Réessayez dans une minute.', 429);
        }
        if (!$this->settings->quoteOnline()) {
            return $this->error('Le devis en ligne est momentanément fermé.', 503);
        }
        try {
            $data = $request->toArray();
        } catch (JsonException) {
            return $this->error('Demande illisible.', 400);
        }
        $raw = $data['lines'] ?? [];
        if (!\is_array($raw) || !array_is_list($raw) || \count($raw) > self::MAX_LINES) {
            return $this->error('Demande illisible.', 400);
        }
        foreach ($raw as $line) {
            if (!\is_array($line) || !\is_int($line['productId'] ?? null) || $line['productId'] < 1) {
                return $this->error('Un produit de la demande est illisible.', 400);
            }
            $quantity = $line['quantity'] ?? null;
            if (!\is_int($quantity) || $quantity < 1 || $quantity > self::MAX_QTY) {
                return $this->error('Chaque quantité doit être un nombre entier, sans virgule, d\'au moins 1.', 400);
            }
        }

        $products = [];
        foreach ($raw ? $this->products->findBy(['id' => array_unique(array_column($raw, 'productId'))]) : [] as $product) {
            $products[$product->getId()] = $product;
        }
        $priced = $positions = $ignored = [];
        foreach ($raw as $i => $line) {
            $product = $products[$line['productId']] ?? null;
            // Masqué depuis l'ajout, inconnu ou sans palier de prix : ignoré et signalé
            if (!$product || !$product->isVisible() || !$product->getCategory()?->isVisible() || null === $this->calculator->fromPrice($product)) {
                $ignored[] = ['line' => $i, 'productId' => $line['productId']];
                continue;
            }
            $positions[] = $i;
            $priced[] = ['product' => $product, 'quantity' => $line['quantity']];
        }

        // `date` est reçue mais servira au parcours Finaliser (story 3.9) : elle ne change pas le prix
        $delivery = 'delivery' === ($data['mode'] ?? null);
        $options = \is_array($data['options'] ?? null) ? $data['options'] : [];
        $address = \is_string($data['address'] ?? null) ? mb_substr(trim($data['address']), 0, 255) : '';
        $meters = $delivery && '' !== $address ? $this->distance->metersFromDepot($address) : null;
        $context = $this->contexts->create($meters, $delivery, true === ($options['pickup'] ?? null), true === ($options['installation'] ?? null), true === ($options['cleaning'] ?? null));
        $quote = $this->calculator->quote($priced, $context);

        // Positions dans la liste reçue (les produits ignorés n'y sont plus)
        $lines = array_map(fn (array $l) => (isset($l['line']) ? ['line' => $positions[$l['line']]] + $l : $l) + [
            'amountText' => $this->money->money($l['amount']),
            'unitPriceText' => isset($l['unitPrice']) ? $this->money->money($l['unitPrice']) : null,
        ], $quote['lines']);
        $reasons = array_map(fn (array $r) => isset($r['line']) ? ['line' => $positions[$r['line']]] + $r : $r, $quote['reasons']);
        if ($delivery && null === $meters) {
            $reasons[] = ['type' => 'distance_unknown', 'message' => self::DISTANCE_UNKNOWN];
        }

        return new JsonResponse([
            'lines' => $lines,
            'total' => $quote['total'],
            'totalText' => $this->money->money($quote['total']),
            'deposit' => $quote['deposit'],
            'depositText' => $this->money->money($quote['deposit']),
            'depositPercent' => intdiv($context->depositBasisPoints, 100),
            'balance' => $quote['balance'],
            'balanceText' => $this->money->money($quote['balance']),
            'excludesDelivery' => $quote['excludesDelivery'] || !$delivery,
            'reasons' => $reasons,
            'ignored' => $ignored,
        ]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
