<?php

namespace App\Service;

/**
 * Période de location (story 3.10, AD-24, AD-28) : seul endroit qui propose les créneaux autour de l'événement
 * et qui convertit le choix d'Inès en startAt / endAt (heure de Paris), revérifié contre SlotProvider.
 */
final class RentalPeriod
{
    public const string DATE_TOO_SOON = 'Choisissez une date au moins 24 h à l\'avance.';

    public function __construct(
        private readonly SlotProvider $slots,
        private readonly SettingsProvider $settings,
    ) {
    }

    /** Date « AAAA-MM-JJ » à minuit, heure de Paris ; null si illisible. */
    public function eventDate(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Paris'));

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** Date passée ou du jour (heure de Paris) : refusée, l'événement est au plus tôt demain. */
    public function tooSoon(\DateTimeImmutable $event, ?\DateTimeImmutable $now = null): bool
    {
        $today = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d');

        return $event->format('Y-m-d') <= $today;
    }

    /**
     * Plages ouvertes et à venir : retrait de J − n à J, retour de J à J + n.
     * n = fenêtre réglée, plus courte sous le seuil de chaises (Réglages → Devis) ; réglage vide = jour J seulement.
     *
     * @return array{pickup: list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}>, return: list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}>}
     */
    public function choices(\DateTimeImmutable $event, int $chairs, ?\DateTimeImmutable $now = null): array
    {
        $small = $this->int(SettingsProvider::SLOT_WINDOW_SMALL_CHAIRS);
        $days = $this->int(null !== $small && $chairs < $small ? SettingsProvider::SLOT_WINDOW_SMALL_DAYS : SettingsProvider::SLOT_WINDOW_DAYS) ?? 0;
        $now ??= new \DateTimeImmutable();
        $open = fn (int $from, int $to) => array_values(array_filter(
            array_merge(...array_map(fn (int $d) => $this->slots->openSlots($event->modify("$d days")), range($from, $to))),
            fn (array $slot) => $slot['start'] > $now,
        ));

        return ['pickup' => $open(-$days, 0), 'return' => $open(0, $days)];
    }

    /**
     * Choix d'Inès → période. Retrait : `pickupSlot` puis `returnSlot` (début du créneau, « AAAA-MM-JJTHH:MM »).
     * Livraison : `deliveryDate`, puis `collectDate` avec récupération (journées entières, l'horaire se fixe avec Serife),
     * sinon `returnSlot` au dépôt. Refusé : le champ en cause et un message qui explique la règle.
     *
     * @param array<string, mixed> $choice
     *
     * @return array{startAt: \DateTimeImmutable, endAt: \DateTimeImmutable}|array{field: string, error: string}
     */
    public function resolve(\DateTimeImmutable $event, int $chairs, bool $delivery, bool $collect, array $choice, ?\DateTimeImmutable $now = null): array
    {
        $choices = $this->choices($event, $chairs, $now);
        // Créneau (ou journée) proposé qui correspond au choix ; message sinon
        $find = function (string $field, string $list, string $format, string $missing, string $rule) use ($choices, $choice): array {
            $value = $choice[$field] ?? null;
            if (!\is_string($value) || '' === $value) {
                return ['field' => $field, 'error' => $missing];
            }
            foreach ($choices[$list] as $slot) {
                if ($slot['start']->format($format) === $value) {
                    return $slot;
                }
            }

            return ['field' => $field, 'error' => $rule];
        };

        if ($delivery) {
            $start = $find('deliveryDate', 'pickup', 'Y-m-d', 'Choisissez la date de livraison.', 'Choisissez une date de livraison proposée : au plus tard le jour de l\'événement.');
            if (isset($start['error'])) {
                return $start;
            }
            $startAt = $start['start']->setTime(0, 0);
            if ($collect) {
                $end = $find('collectDate', 'return', 'Y-m-d', 'Choisissez la date de récupération.', 'Choisissez une date de récupération proposée : au plus tôt le jour de l\'événement.');

                // Date sans horaire : la journée entière
                return isset($end['error']) ? $end : ['startAt' => $startAt, 'endAt' => $end['start']->setTime(23, 59, 59)];
            }
            $end = $find('returnSlot', 'return', 'Y-m-d\TH:i', 'Choisissez un créneau de retour au dépôt.', 'Choisissez un créneau de retour proposé : au plus tôt le jour de l\'événement.');

            return isset($end['error']) ? $end : ['startAt' => $startAt, 'endAt' => $end['end']];
        }

        $start = $find('pickupSlot', 'pickup', 'Y-m-d\TH:i', 'Choisissez un créneau de retrait.', 'Choisissez un créneau de retrait proposé : au plus tard le jour de l\'événement.');
        if (isset($start['error'])) {
            return $start;
        }
        $end = $find('returnSlot', 'return', 'Y-m-d\TH:i', 'Choisissez un créneau de retour.', 'Choisissez un créneau de retour proposé : au plus tôt le jour de l\'événement.');
        if (isset($end['error'])) {
            return $end;
        }
        if ($end['start'] < $start['end']) {
            return ['field' => 'returnSlot', 'error' => 'Le retour a lieu après le retrait : choisissez un créneau de retour plus tard.'];
        }

        return ['startAt' => $start['start'], 'endAt' => $end['end']];
    }

    private function int(string $key): ?int
    {
        $value = trim($this->settings->get($key));

        return ctype_digit($value) ? (int) $value : null;
    }
}
