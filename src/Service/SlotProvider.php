<?php

namespace App\Service;

use App\Repository\DepotClosureRepository;
use App\Repository\SlotRepository;

/** Seul endroit qui dit quand le dépôt est ouvert un jour donné (story 3.3), en heure de Paris (AD-24). */
final class SlotProvider
{
    public function __construct(
        private readonly SlotRepository $slots,
        private readonly DepotClosureRepository $closures,
    ) {
    }

    /**
     * Plages ouvertes du jour calendaire de `$date` (sa date seule compte), triées ; vide si fermé.
     *
     * @return list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}>
     */
    public function openSlots(\DateTimeImmutable $date): array
    {
        $day = new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('Europe/Paris'));
        if ($this->closures->isClosedOn($day)) {
            return [];
        }
        $at = fn (\DateTimeImmutable $time) => $day->setTime((int) $time->format('G'), (int) $time->format('i'));

        return array_map(
            fn ($slot) => ['start' => $at($slot->getStartsAt()), 'end' => $at($slot->getEndsAt())],
            $this->slots->findBy(['weekday' => (int) $day->format('N')], ['startsAt' => 'ASC']),
        );
    }
}
