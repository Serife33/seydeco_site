<?php

namespace App\Controller;

use App\Entity\Slot;
use App\Service\PricingCalculator;
use App\Service\PricingContextFactory;
use App\Service\QuoteEstimator;
use App\Service\RentalPeriod;
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
 * JSON in : {lines: [{productId, quantity}], date, mode: pickup|delivery, address, options: {pickup, installation, cleaning}, period}.
 * `options.pickup` = récupération après une livraison ; `period` = choix de l'étape Créneaux de Finaliser (RentalPeriod::resolve()).
 * Sans session ni cookie ; ~120 appels/min par IP.
 */
final class EstimateController extends AbstractController
{
    public const string DISTANCE_UNKNOWN = 'Adresse introuvable : les frais de livraison seront confirmés par Serife.';

    public function __construct(
        private readonly QuoteEstimator $estimator,
        private readonly SettingsProvider $settings,
        private readonly PricingCalculator $calculator,
        private readonly PricingContextFactory $contexts,
        private readonly MoneyExtension $money,
        private readonly RateLimiterFactoryInterface $estimateLimiter,
        private readonly RentalPeriod $period,
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
            ['priced' => $priced, 'positions' => $positions, 'ignored' => $ignored, 'delivery' => $delivery, 'collect' => $collect,
                'meters' => $meters, 'context' => $context, 'quote' => $quote, 'chairs' => $chairs] = $this->estimator->estimate($data);
        } catch (JsonException) {
            return $this->error('Demande illisible.', 400);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        }

        // Positions dans la liste reçue (les produits ignorés n'y sont plus)
        $lines = array_map(fn (array $l) => (isset($l['line']) ? ['line' => $positions[$l['line']]] + $l : $l) + [
            'amountText' => $this->money->money($l['amount']),
            'unitPriceText' => isset($l['unitPrice']) ? $this->money->money($l['unitPrice']) : null,
        ], $quote['lines']);
        $reasons = array_map(fn (array $r) => isset($r['line']) ? ['line' => $positions[$r['line']]] + $r : $r, $quote['reasons']);
        if ($delivery && null === $meters) {
            $reasons[] = ['type' => 'distance_unknown', 'message' => self::DISTANCE_UNKNOWN];
        }
        // Tables suggérées (story 3.7), affichées dans Souvent loué avec ; `line` = position de la table dans la liste reçue
        $tables = $this->calculator->suggestedTables($priced);
        if ($tables) {
            $tables = [
                'line' => $positions[$tables['line']],
                'productName' => $priced[$tables['line']]['product']->getName(),
                'missing' => $tables['missing'],
                'text' => \sprintf('Pour %d chaises, il faut environ %d tables de %d places.', $tables['chairs'], $tables['needed'], $tables['seats']),
                'buttonText' => \sprintf('Ajouter %d table%s', $tables['missing'], $tables['missing'] > 1 ? 's' : ''),
            ];
        }

        // Finaliser (stories 3.9, 3.10) : la date ne change pas le prix ; elle donne les créneaux et la période revérifiée
        $date = $dateError = $slots = $period = null;
        if (null !== ($data['date'] ?? null) && '' !== $data['date']) {
            $date = $this->period->eventDate($data['date']);
            $dateError = null === $date ? 'Saisissez une date valide, par exemple 14/06/2027.' : ($this->period->tooSoon($date) ? RentalPeriod::DATE_TOO_SOON : null);
        }
        if ($date && null === $dateError) {
            $slots = array_map($this->days(...), $this->period->choices($date, $chairs));
            if (\is_array($data['period'] ?? null)) {
                $period = $this->period->resolve($date, $chairs, $delivery, $collect, $data['period']);
                $period = isset($period['error']) ? $period : ['startAt' => $period['startAt']->format(\DATE_ATOM), 'endAt' => $period['endAt']->format(\DATE_ATOM)];
            }
        }
        // Livraison possible ? Raisons chiffrées même en retrait, pour rendre l'option inactive (story 3.9)
        $deliveryReasons = array_values(array_filter(
            $delivery ? $reasons : $this->calculator->quote($priced, $this->contexts->create(null, true))['reasons'],
            fn (array $r) => \in_array($r['type'], ['delivery_min_chairs', 'delivery_max_km'], true),
        ));
        // Tarif d'installation de chaque catégorie de la sélection (« tarif sur demande » si vide)
        $installation = [];
        foreach ($priced as ['product' => $product]) {
            $category = $product->getCategory();
            $price = $context->installPrices[$category->getId()] ?? null;
            $installation[$category->getId()] = ['label' => $category->getName(), 'text' => null === $price ? 'tarif sur demande' : $this->money->money($price).' l\'unité'];
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
            'tables' => $tables,
            'chairs' => $chairs,
            'deliveryReasons' => $deliveryReasons,
            'installation' => array_values($installation),
            'dateError' => $dateError,
            'slots' => $slots,
            'period' => $period,
        ]);
    }

    /**
     * Plages groupées par jour pour la grille de Finaliser : valeur = début « AAAA-MM-JJTHH:MM », heure de Paris.
     *
     * @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $slots
     *
     * @return list<array{date: string, label: string, slots: list<array{value: string, label: string}>}>
     */
    private function days(array $slots): array
    {
        $format = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'Europe/Paris', null, 'EEEE d MMMM');
        $days = [];
        foreach ($slots as ['start' => $start, 'end' => $end]) {
            $date = $start->format('Y-m-d');
            $days[$date] ??= ['date' => $date, 'label' => mb_ucfirst((string) $format->format($start)), 'slots' => []];
            $days[$date]['slots'][] = ['value' => $start->format('Y-m-d\TH:i'), 'label' => Slot::hour($start).' – '.Slot::hour($end)];
        }

        return array_values($days);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
