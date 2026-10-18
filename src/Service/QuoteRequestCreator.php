<?php

namespace App\Service;

use App\Entity\QuoteLine;
use App\Entity\QuoteLineType;
use App\Entity\QuoteRequest;
use App\Entity\QuoteVersion;
use App\Twig\SettingsExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Enregistre une demande validée (story 3.12) en une seule transaction : numéro, demande, première version figée et ses lignes,
 * marquage initial `nouvelle` du workflow (qui écrit le StatEvent « devis créé »). Un échec n'enregistre rien.
 */
final class QuoteRequestCreator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentNumberer $numberer,
        #[Target('quote_request')]
        private readonly WorkflowInterface $workflow,
        private readonly SettingsExtension $settings,
    ) {
    }

    /**
     * @param array<string, mixed>                                         $estimate QuoteEstimator::estimate()
     * @param array{startAt: \DateTimeImmutable, endAt: \DateTimeImmutable} $period   RentalPeriod::resolve()
     */
    public function create(QuoteRequest $request, array $estimate, \DateTimeImmutable $eventDate, array $period, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $this->em->wrapInTransaction(function () use ($request, $estimate, $eventDate, $period, $now): void {
            $request->assignNumber($this->numberer->next(DocumentNumberer::QUOTE, $now));
            $request->acceptConsents($now);

            $quote = $estimate['quote'];
            $context = $estimate['context'];
            $delivery = null;
            $discount = null;
            foreach ($quote['lines'] as $line) {
                $delivery ??= 'delivery' === $line['type'] ? $line : null;
                $discount ??= 'discount' === $line['type'] ? $line['amount'] : null;
            }
            $version = new QuoteVersion(
                $request,
                1,
                $eventDate,
                $period['startAt'],
                $period['endAt'],
                $estimate['delivery'] ? QuoteVersion::MODE_DELIVERY : QuoteVersion::MODE_PICKUP,
                $estimate['collect'],
                $context->installation,
                $context->cleaning,
                $estimate['delivery'] ? $estimate['address'] : null,
                // Contexte figé (AD-8) : réglages du calcul, distance et voyages, remise, chaises, conditions et moyens de paiement du jour
                get_object_vars($context) + [
                    'km' => $delivery['km'] ?? null,
                    'trips' => $delivery['trips'] ?? null,
                    'tablesWithChairsDiscount' => $discount,
                    'conditions' => $this->settings->quoteConditions(),
                    'chairs' => $estimate['chairs'],
                    // Coordonnées de Seydeco imprimées sur le PDF (story 3.13)
                    'seller' => array_intersect_key($this->settings->siteContact(), array_flip(['address', 'phone', 'email', 'siret'])),
                ],
                $quote['total'],
                $quote['deposit'],
                $quote['excludesDelivery'],
            );
            foreach ($quote['lines'] as $i => $l) {
                $line = new QuoteLine($version, $i + 1, QuoteLineType::from($l['type']), $l['label'], $l['amount'], $l['quantity'] ?? null, $l['unitPrice'] ?? null);
                if ('product' === $l['type']) {
                    ['product' => $product, 'color' => $color] = $estimate['priced'][$l['line']];
                    $line->copyProduct($product, $color?->getName());
                }
                $version->addLine($line);
            }
            $request->addVersion($version);
            $this->em->persist($request);
            // Démarre à `nouvelle` sans setter de statut (AD-6)
            $this->workflow->getMarking($request);
            $this->em->flush();
        });
    }
}
