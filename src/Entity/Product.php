<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_product_slug', fields: ['slug'])]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Saisir un nom.')]
    #[Assert\Length(max: 150, maxMessage: 'Le nom ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $name = null;

    /** Généré à la création puis figé. */
    #[ORM\Column(length: 170)]
    private ?string $slug = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Choisir une catégorie.')]
    private ?Category $category = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'Les dimensions ne doivent pas dépasser {{ limit }} caractères.')]
    private ?string $dimensions = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'Le nombre de places doit être au moins 1.')]
    #[Assert\LessThanOrEqual(1000000, message: 'Le nombre de places ne peut pas dépasser 1 000 000.')]
    private ?int $seats = null;

    /** @var Collection<int, Style> */
    #[ORM\ManyToMany(targetEntity: Style::class)]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $styles;

    /** @var Collection<int, EventType> */
    #[ORM\ManyToMany(targetEntity: EventType::class)]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $eventTypes;

    #[ORM\Column]
    #[Assert\NotNull(message: 'Saisir une quantité minimale.')]
    #[Assert\Positive(message: 'La quantité minimale doit être au moins 1.')]
    #[Assert\LessThanOrEqual(1000000, message: 'La quantité minimale ne peut pas dépasser 1 000 000.')]
    private ?int $minQty = 1;

    #[ORM\Column]
    #[Assert\NotNull(message: 'Saisir le stock.')]
    #[Assert\PositiveOrZero(message: 'Le stock ne peut pas être négatif.')]
    #[Assert\LessThanOrEqual(1000000, message: 'Le stock ne peut pas dépasser 1 000 000.')]
    private ?int $stock = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private bool $visible = true;

    public function __construct()
    {
        $this->styles = new ArrayCollection();
        $this->eventTypes = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
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

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): void
    {
        $this->category = $category;
    }

    public function getDimensions(): ?string
    {
        return $this->dimensions;
    }

    public function setDimensions(?string $dimensions): void
    {
        $this->dimensions = $dimensions;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getSeats(): ?int
    {
        return $this->seats;
    }

    public function setSeats(?int $seats): void
    {
        $this->seats = $seats;
    }

    /** @return Collection<int, Style> */
    public function getStyles(): Collection
    {
        return $this->styles;
    }

    public function addStyle(Style $style): void
    {
        if (!$this->styles->contains($style)) {
            $this->styles->add($style);
        }
    }

    public function removeStyle(Style $style): void
    {
        $this->styles->removeElement($style);
    }

    /** @return Collection<int, EventType> */
    public function getEventTypes(): Collection
    {
        return $this->eventTypes;
    }

    public function addEventType(EventType $eventType): void
    {
        if (!$this->eventTypes->contains($eventType)) {
            $this->eventTypes->add($eventType);
        }
    }

    public function removeEventType(EventType $eventType): void
    {
        $this->eventTypes->removeElement($eventType);
    }

    public function getMinQty(): ?int
    {
        return $this->minQty;
    }

    public function setMinQty(?int $minQty): void
    {
        $this->minQty = $minQty;
    }

    public function getStock(): ?int
    {
        return $this->stock;
    }

    public function setStock(?int $stock): void
    {
        $this->stock = $stock;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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
