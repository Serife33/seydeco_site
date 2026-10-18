<?php

namespace App\Tests\Service;

use App\Entity\Setting;
use App\Entity\Slot;
use App\Repository\DepotClosureRepository;
use App\Repository\SlotRepository;
use App\Service\RentalPeriod;
use App\Service\SettingsProvider;
use App\Service\SlotProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Story 3.10 : créneaux autour de l'événement (samedi 12/06/2027) et conversion du choix en période, heure de Paris.
 * Dépôt ouvert du lundi au samedi 9 h 30 – 12 h, et le samedi aussi 14 h – 18 h ; fermé le dimanche et le lundi 14/06 (fermeture).
 */
final class RentalPeriodTest extends TestCase
{
    private const string EVENT = '2027-06-12';

    private function period(array $settings = ['slot_window_days' => '2', 'slot_window_small_days' => '1', 'slot_window_small_chairs' => '50']): RentalPeriod
    {
        $slot = function (int $weekday, string $start, string $end): Slot {
            $slot = new Slot();
            $slot->setWeekday($weekday);
            $slot->setStartsAt(new \DateTimeImmutable("1970-01-01 $start"));
            $slot->setEndsAt(new \DateTimeImmutable("1970-01-01 $end"));

            return $slot;
        };
        $slots = $this->createStub(SlotRepository::class);
        $slots->method('findBy')->willReturnCallback(fn (array $criteria) => match ($criteria['weekday']) {
            7 => [],
            6 => [$slot(6, '09:30', '12:00'), $slot(6, '14:00', '18:00')],
            default => [$slot($criteria['weekday'], '09:30', '12:00')],
        });
        $closures = $this->createStub(DepotClosureRepository::class);
        $closures->method('isClosedOn')->willReturnCallback(fn (\DateTimeImmutable $day) => '2027-06-14' === $day->format('Y-m-d'));
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn(array_map(fn ($name, $value) => new Setting($name, $value), array_keys($settings), $settings));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        return new RentalPeriod(new SlotProvider($slots, $closures), new SettingsProvider($em));
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-06-10 10:00', new \DateTimeZone('Europe/Paris'));
    }

    /** @return list<string> */
    private static function starts(array $slots): array
    {
        return array_map(fn (array $s) => $s['start']->format('D d H:i'), $slots);
    }

    public function testEventDateIsReadAndMustBeAtLeastTomorrowInParis(): void
    {
        $period = $this->period();
        self::assertNull($period->eventDate('12/06/2027'));
        self::assertNull($period->eventDate('2027-02-30'));
        self::assertSame('2027-06-12T00:00:00+02:00', $period->eventDate(self::EVENT)->format('c'));

        // 23 h 30 à Londres le 10 = déjà le 11 à Paris : le 11 est « aujourd'hui »
        $now = new \DateTimeImmutable('2027-06-10 22:30', new \DateTimeZone('UTC'));
        self::assertTrue($period->tooSoon($period->eventDate('2027-06-09'), $now));
        self::assertTrue($period->tooSoon($period->eventDate('2027-06-11'), $now));
        self::assertFalse($period->tooSoon($period->eventDate(self::EVENT), $now));
    }

    public function testWindowIsTwoDaysOrOneUnderFiftyChairsWithoutClosedOrPastSlots(): void
    {
        $event = $this->period()->eventDate(self::EVENT);

        // Jeudi 9 h 30 déjà passé ; dimanche fermé, lundi en fermeture exceptionnelle
        $choices = $this->period()->choices($event, 50, self::now());
        self::assertSame(['Fri 11 09:30', 'Sat 12 09:30', 'Sat 12 14:00'], self::starts($choices['pickup']));
        self::assertSame(['Sat 12 09:30', 'Sat 12 14:00'], self::starts($choices['return']));
        self::assertSame('2027-06-12T18:00:00+02:00', $choices['return'][1]['end']->format('c'));

        $choices = $this->period()->choices($event, 49, new \DateTimeImmutable('2027-06-01'));
        self::assertSame(['Fri 11 09:30', 'Sat 12 09:30', 'Sat 12 14:00'], self::starts($choices['pickup']));

        // Fenêtre réglée à 3 jours : le retour du mardi apparaît
        $choices = $this->period(['slot_window_days' => '3'])->choices($event, 120, new \DateTimeImmutable('2027-06-01'));
        self::assertSame(['Wed 09 09:30', 'Thu 10 09:30', 'Fri 11 09:30', 'Sat 12 09:30', 'Sat 12 14:00'], self::starts($choices['pickup']));
        self::assertSame(['Sat 12 09:30', 'Sat 12 14:00', 'Tue 15 09:30'], self::starts($choices['return']));

        // Réglages vides : jour de l'événement seulement
        self::assertSame(['Sat 12 09:30', 'Sat 12 14:00'], self::starts($this->period([])->choices($event, 120, self::now())['pickup']));
    }

    public function testPickupChoiceBecomesPeriodCheckedAgainstSlots(): void
    {
        $period = $this->period();
        $event = $period->eventDate(self::EVENT);
        $resolve = fn (array $choice) => $period->resolve($event, 120, false, false, $choice, self::now());

        $ok = $resolve(['pickupSlot' => '2027-06-11T09:30', 'returnSlot' => '2027-06-12T14:00']);
        self::assertSame(['2027-06-11T09:30:00+02:00', '2027-06-12T18:00:00+02:00'], [$ok['startAt']->format('c'), $ok['endAt']->format('c')]);

        self::assertSame(['field' => 'pickupSlot', 'error' => 'Choisissez un créneau de retrait.'], $resolve([]));
        // Jeudi passé, ou créneau qui n'existe pas
        foreach (['2027-06-10T09:30', '2027-06-11T10:00', '2027-06-13T09:30'] as $slot) {
            self::assertSame('Choisissez un créneau de retrait proposé : au plus tard le jour de l\'événement.', $resolve(['pickupSlot' => $slot, 'returnSlot' => '2027-06-12T14:00'])['error']);
        }
        self::assertSame(['field' => 'returnSlot', 'error' => 'Choisissez un créneau de retour proposé : au plus tôt le jour de l\'événement.'], $resolve(['pickupSlot' => '2027-06-11T09:30', 'returnSlot' => '2027-06-11T09:30']));
        self::assertSame(['field' => 'returnSlot', 'error' => 'Le retour a lieu après le retrait : choisissez un créneau de retour plus tard.'], $resolve(['pickupSlot' => '2027-06-12T14:00', 'returnSlot' => '2027-06-12T09:30']));
        self::assertSame('returnSlot', $resolve(['pickupSlot' => '2027-06-12T09:30', 'returnSlot' => '2027-06-12T09:30'])['field']);
    }

    public function testDeliveryDatesCoverWholeDays(): void
    {
        $period = $this->period();
        $event = $period->eventDate(self::EVENT);

        $collect = $period->resolve($event, 120, true, true, ['deliveryDate' => '2027-06-11', 'collectDate' => '2027-06-12'], self::now());
        self::assertSame(['2027-06-11T00:00:00+02:00', '2027-06-12T23:59:59+02:00'], [$collect['startAt']->format('c'), $collect['endAt']->format('c')]);

        // Sans récupération : retour au dépôt dans la grille
        $return = $period->resolve($event, 120, true, false, ['deliveryDate' => '2027-06-12', 'returnSlot' => '2027-06-12T14:00'], self::now());
        self::assertSame(['2027-06-12T00:00:00+02:00', '2027-06-12T18:00:00+02:00'], [$return['startAt']->format('c'), $return['endAt']->format('c')]);

        self::assertSame(['field' => 'deliveryDate', 'error' => 'Choisissez la date de livraison.'], $period->resolve($event, 120, true, true, [], self::now()));
        self::assertSame('deliveryDate', $period->resolve($event, 120, true, true, ['deliveryDate' => '2027-06-13', 'collectDate' => '2027-06-12'], self::now())['field']);
        self::assertSame(['field' => 'collectDate', 'error' => 'Choisissez une date de récupération proposée : au plus tôt le jour de l\'événement.'], $period->resolve($event, 120, true, true, ['deliveryDate' => '2027-06-11', 'collectDate' => '2027-06-14'], self::now()));
        self::assertSame(['field' => 'returnSlot', 'error' => 'Choisissez un créneau de retour au dépôt.'], $period->resolve($event, 120, true, false, ['deliveryDate' => '2027-06-11'], self::now()));
    }
}
