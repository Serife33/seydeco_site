<?php

namespace App\EventListener;

use App\Entity\QuoteLineType;
use App\Entity\QuoteRequest;
use App\Entity\StatEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Workflow\Attribute\AsEnteredListener;
use Symfony\Component\Workflow\Event\EnteredEvent;

/**
 * Seul auteur des statistiques (AD-20) : écrit un StatEvent anonyme quand une demande entre dans un état suivi.
 * « Devis créé » = marquage initial `nouvelle` (aucune transition n'y mène) ; acompte reçu, terminée, annulée, expirée : Epic 4.
 * Persisté dans la transaction de création, sans flush ici.
 */
final class StatsRecorder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[AsEnteredListener(workflow: 'quote_request', place: 'nouvelle')]
    public function quoteCreated(EnteredEvent $event): void
    {
        /** @var QuoteRequest $request */
        $request = $event->getSubject();
        $version = $request->getCurrentVersion();
        $products = [];
        foreach ($version?->getLines() ?? [] as $line) {
            if (QuoteLineType::Product === $line->getType()) {
                $products[] = ['id' => $line->getProduct()?->getId(), 'name' => $line->getLabel(), 'quantity' => (int) $line->getQuantity()];
            }
        }
        $this->em->persist(new StatEvent(
            $request->getStatKey(),
            StatEvent::QUOTE_CREATED,
            $request->getCreatedAt(),
            $request->getSource(),
            $request->isPro(),
            $version?->getTotal(),
            $products ?: null,
        ));
    }
}
