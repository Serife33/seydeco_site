<?php

namespace App\Entity;

use App\Repository\DepotClosureRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Fermeture exceptionnelle du dépôt, du début à la fin incluses (story 3.3). « Closure » est déjà une classe de PHP. */
#[ORM\Entity(repositoryClass: DepotClosureRepository::class)]
class DepotClosure
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Saisir la date de fermeture.')]
    private ?\DateTimeImmutable $startsOn = null;

    /** Jamais vide en base : une fermeture d'un jour a la même date de début et de fin. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\GreaterThanOrEqual(propertyPath: 'startsOn', message: 'La date de fin ne peut pas être avant la date de début.')]
    private ?\DateTimeImmutable $endsOn = null;

    /** Motif interne, jamais affiché sur le site. */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120, maxMessage: 'Le motif ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $reason = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStartsOn(): ?\DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function setStartsOn(?\DateTimeImmutable $startsOn): void
    {
        $this->startsOn = $startsOn;
    }

    public function getEndsOn(): ?\DateTimeImmutable
    {
        return $this->endsOn;
    }

    /** Vide = un seul jour. */
    public function setEndsOn(?\DateTimeImmutable $endsOn): void
    {
        $this->endsOn = $endsOn ?? $this->startsOn;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): void
    {
        $this->reason = null === $reason ? null : (trim($reason) ?: null);
    }
}
