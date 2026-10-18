<?php

namespace App\Tests\Service;

use App\Entity\Slot;
use App\Repository\DepotClosureRepository;
use App\Repository\SlotRepository;
use App\Service\SlotProvider;
use PHPUnit\Framework\TestCase;

/** Story 3.3 : plages ouvertes d'un jour, en heure de Paris. */
final class SlotProviderTest extends TestCase
{
    private function provider(bool $closed, int $weekday = 7): SlotProvider
    {
        $slot = new Slot();
        $slot->setWeekday($weekday);
        $slot->setStartsAt(new \DateTimeImmutable('1970-01-01 09:30'));
        $slot->setEndsAt(new \DateTimeImmutable('1970-01-01 12:00'));
        $slots = $this->createStub(SlotRepository::class);
        $slots->method('findBy')->willReturnCallback(fn (array $criteria) => $criteria['weekday'] === $weekday ? [$slot] : []);
        $closures = $this->createStub(DepotClosureRepository::class);
        $closures->method('isClosedOn')->willReturn($closed);

        return new SlotProvider($slots, $closures);
    }

    public function testOpenDayGivesItsSlotsInParisTime(): void
    {
        // 28/03/2027 : passage à l'heure d'été à 2 h ; la veille est encore en heure d'hiver
        $after = $this->provider(false)->openSlots(new \DateTimeImmutable('2027-03-28'));
        self::assertSame('2027-03-28T09:30:00+02:00', $after[0]['start']->format('c'));
        self::assertSame('2027-03-28T12:00:00+02:00', $after[0]['end']->format('c'));

        $before = $this->provider(false, 6)->openSlots(new \DateTimeImmutable('2027-03-27'));
        self::assertSame('2027-03-27T09:30:00+01:00', $before[0]['start']->format('c'));
    }

    public function testDayWithoutSlotIsClosed(): void
    {
        self::assertSame([], $this->provider(false)->openSlots(new \DateTimeImmutable('2027-03-29')));
    }

    public function testClosureEmptiesTheDay(): void
    {
        self::assertSame([], $this->provider(true)->openSlots(new \DateTimeImmutable('2027-03-28')));
    }
}
