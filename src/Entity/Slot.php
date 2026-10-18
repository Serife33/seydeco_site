<?php

namespace App\Entity;

use App\Repository\SlotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Plage d'ouverture du dépôt un jour de la semaine (story 3.3) : une seule grille pour le retrait et le retour.
 * Une demande copie sa période dans sa version : retirer une plage ne change aucune demande (AD-28).
 */
#[ORM\Entity(repositoryClass: SlotRepository::class)]
class Slot
{
    public const array WEEKDAYS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** 1 = lundi … 7 = dimanche (ISO-8601, `format('N')`). */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Range(min: 1, max: 7)]
    private int $weekday = 1;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    #[Assert\NotNull(message: 'Saisir l\'heure de début.')]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    #[Assert\NotNull(message: 'Saisir l\'heure de fin.')]
    #[Assert\GreaterThan(propertyPath: 'startsAt', message: 'L\'heure de fin doit être après l\'heure de début.')]
    private ?\DateTimeImmutable $endsAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWeekday(): int
    {
        return $this->weekday;
    }

    public function setWeekday(int $weekday): void
    {
        $this->weekday = $weekday;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): void
    {
        $this->startsAt = $startsAt;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(?\DateTimeImmutable $endsAt): void
    {
        $this->endsAt = $endsAt;
    }

    public function overlaps(self $other): bool
    {
        return $this->weekday === $other->weekday
            && $this->startsAt?->format('H:i') < $other->endsAt?->format('H:i')
            && $other->startsAt?->format('H:i') < $this->endsAt?->format('H:i');
    }

    /** « 14 h – 16 h », « 9 h 30 – 12 h ». */
    public function label(): string
    {
        return self::hour($this->startsAt).' – '.self::hour($this->endsAt);
    }

    private static function hour(?\DateTimeImmutable $time): string
    {
        return $time?->format('00' === $time->format('i') ? 'G \h' : 'G \h i') ?? '';
    }
}
