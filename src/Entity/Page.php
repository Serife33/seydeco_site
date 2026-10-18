<?php

namespace App\Entity;

use App\Repository\PageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Page de contenu (story 2.3) servie sur /{slug} : À propos, Contact, pages légales (créées par migration) et pages de Serife.
 * Se masque, ne s'efface jamais.
 */
#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: PageRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_page_slug', fields: ['slug'])]
class Page
{
    use UpdatedAtTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Saisir un titre.')]
    #[Assert\Length(max: 150, maxMessage: 'Le titre ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $title = null;

    /** Généré à la création puis figé. */
    #[ORM\Column(length: 170)]
    private ?string $slug = null;

    /** Texte mis en forme simple (Markdown restreint, rendu par SimpleMarkdown). */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Saisir le contenu de la page.')]
    private ?string $content = null;

    /** Description pour Google ; vide = début du contenu. */
    #[ORM\Column(length: 160, nullable: true)]
    #[Assert\Length(max: 160, maxMessage: 'La description ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $description = null;

    /** Fichier dans le dossier d'envoi (hors de public/) ; facultatif. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $photo = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'La description ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $photoAlt = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $visible = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function initSlug(string $slug): void
    {
        if (null !== $this->slug) {
            throw new \LogicException('Le slug est figé après la création.');
        }
        $this->slug = $slug;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): void
    {
        $this->content = $content;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function setPhoto(?string $photo): void
    {
        $this->photo = $photo;
    }

    public function getPhotoAlt(): ?string
    {
        return $this->photoAlt;
    }

    public function setPhotoAlt(?string $photoAlt): void
    {
        $this->photoAlt = $photoAlt;
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
