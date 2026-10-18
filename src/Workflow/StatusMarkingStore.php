<?php

namespace App\Workflow;

use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;

/**
 * Statut d'une state machine lu et écrit dans la propriété privée `status` (AD-6) : l'entité n'a aucun setter de statut,
 * seul le workflow le change (le stockage « method » de Symfony exige un setter public).
 */
final class StatusMarkingStore implements MarkingStoreInterface
{
    public function getMarking(object $subject): Marking
    {
        $status = (fn (): ?string => $this->status)->call($subject);

        return new Marking(null === $status ? [] : [$status => 1]);
    }

    public function setMarking(object $subject, Marking $marking, array $context = []): void
    {
        $status = array_key_first($marking->getPlaces());
        (function () use ($status): void { $this->status = $status; })->call($subject);
    }
}
