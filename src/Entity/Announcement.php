<?php

namespace App\Entity;

use App\Repository\AnnouncementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Annonce du bandeau défilant (story 2.7), affichée du début à la fin incluses (Europe/Paris). Se masque, ne s'efface jamais. */
#[ORM\Entity(repositoryClass: AnnouncementRepository::class)]
class Announcement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Saisir le texte de l\'annonce.', normalizer: 'trim')]
    #[Assert\Length(max: 120, maxMessage: 'Le texte ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $text = null;

    /** Adresse du site (/location/chaises) ou lien complet (https://…) ; « // » et « /\ » refusés (lien vers un autre site déguisé). */
    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    #[Assert\Regex(pattern: '~^(/(?![/\\\\])|https?://[\w-]+(\.[\w-]+)+([:/?#]|$))\S*$~i', message: 'Saisir une adresse du site (/location) ou un lien complet (https://…).')]
    private ?string $link = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Saisir la date de début.')]
    private ?\DateTimeImmutable $startsOn = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Saisir la date de fin.')]
    #[Assert\GreaterThanOrEqual(propertyPath: 'startsOn', message: 'La date de fin ne peut pas être avant la date de début.')]
    private ?\DateTimeImmutable $endsOn = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $visible = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): void
    {
        $this->text = null === $text ? null : trim($text);
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function setLink(?string $link): void
    {
        $this->link = null === $link ? null : (trim($link) ?: null);
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

    public function setEndsOn(?\DateTimeImmutable $endsOn): void
    {
        $this->endsOn = $endsOn;
    }

    /** « À venir », « En cours » ou « Terminée » au jour donné (bornes incluses). */
    public function status(\DateTimeImmutable $today): string
    {
        $day = $today->format('Y-m-d');

        return match (true) {
            $day < $this->startsOn?->format('Y-m-d') => 'À venir',
            $day > $this->endsOn?->format('Y-m-d') => 'Terminée',
            default => 'En cours',
        };
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function setVisible(bool $visible): void
    {
        $this->visible = $visible;
    }
}
