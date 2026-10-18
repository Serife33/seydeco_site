<?php

namespace App\Entity;

use App\Repository\ReviewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Avis Google recopié par Serife (story 2.8), affiché dans le Bloc avis. Se masque, ne s'efface jamais. */
#[ORM\Entity(repositoryClass: ReviewRepository::class)]
class Review
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Prénom et initiale (« Inès B. »). */
    #[ORM\Column(length: 60)]
    #[Assert\NotBlank(message: 'Saisir le prénom et l\'initiale.', normalizer: 'trim')]
    #[Assert\Length(max: 60, maxMessage: 'Le nom ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $author = null;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\NotNull(message: 'Choisir la note.')]
    #[Assert\Range(notInRangeMessage: 'La note va de {{ min }} à {{ max }}.', min: 1, max: 5)]
    private ?int $rating = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Saisir le texte de l\'avis.', normalizer: 'trim')]
    #[Assert\Length(max: 2000, maxMessage: 'Le texte ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $text = null;

    /** Date de l'avis sur Google. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Saisir la date de l\'avis.')]
    private ?\DateTimeImmutable $reviewedOn = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $visible = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function setAuthor(?string $author): void
    {
        $this->author = null === $author ? null : trim($author);
    }

    public function getRating(): ?int
    {
        return $this->rating;
    }

    public function setRating(?int $rating): void
    {
        $this->rating = $rating;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): void
    {
        $this->text = null === $text ? null : trim($text);
    }

    public function getReviewedOn(): ?\DateTimeImmutable
    {
        return $this->reviewedOn;
    }

    public function setReviewedOn(?\DateTimeImmutable $reviewedOn): void
    {
        $this->reviewedOn = $reviewedOn;
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
