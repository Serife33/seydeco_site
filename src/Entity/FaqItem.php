<?php

namespace App\Entity;

use App\Repository\FaqItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Question fréquente (story 2.4), affichée dépliée sur /questions-frequentes. Se masque, ne s'efface jamais. */
#[ORM\Entity(repositoryClass: FaqItemRepository::class)]
class FaqItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Saisir la question.')]
    #[Assert\Length(max: 255, maxMessage: 'La question ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $question = null;

    /** Texte mis en forme simple, comme les pages. */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Saisir la réponse.')]
    private ?string $answer = null;

    /** Facultatif (Livraison, Caution, Retour…) : les questions d'un même thème sont regroupées. */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100, maxMessage: 'Le thème ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $theme = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $visible = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuestion(): ?string
    {
        return $this->question;
    }

    public function setQuestion(?string $question): void
    {
        $this->question = $question;
    }

    public function getAnswer(): ?string
    {
        return $this->answer;
    }

    public function setAnswer(?string $answer): void
    {
        $this->answer = $answer;
    }

    public function getTheme(): ?string
    {
        return $this->theme;
    }

    public function setTheme(?string $theme): void
    {
        $this->theme = null === $theme ? null : (trim($theme) ?: null);
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
