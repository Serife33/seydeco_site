<?php

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_category_name', fields: ['name'])]
#[ORM\UniqueConstraint(name: 'uniq_category_slug', fields: ['slug'])]
#[UniqueEntity(fields: ['name'], message: 'Une catégorie porte déjà ce nom.')]
class Category
{
    use UpdatedAtTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Saisir un nom.')]
    #[Assert\Length(max: 100, maxMessage: 'Le nom ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $name = null;

    /** Généré à la création puis figé : aucun setter public après coup. */
    #[ORM\Column(length: 120)]
    private ?string $slug = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $visible = true;

    #[ORM\Column(length: 10, enumType: PricingType::class)]
    #[Assert\NotNull(message: 'Choisir un type tarifaire.')]
    private ?PricingType $pricingType = null;

    /** Délai de remise en état, en heures. */
    #[ORM\Column]
    #[Assert\NotNull(message: 'Saisir un délai en heures.')]
    #[Assert\PositiveOrZero(message: 'Le délai ne peut pas être négatif.')]
    #[Assert\LessThanOrEqual(1000000, message: 'Le délai ne peut pas dépasser 1 000 000 heures.')]
    private ?int $refurbishDelay = 0;

    /** Centimes ; vide = « tarif sur demande ». */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le montant ne peut pas être négatif.')]
    #[Assert\LessThanOrEqual(100000000, message: 'Le montant ne peut pas dépasser 1 000 000,00 €.')]
    private ?int $installPrice = null;

    /** Centimes, copié dans le produit à sa création (story 1.6). */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le montant ne peut pas être négatif.')]
    #[Assert\LessThanOrEqual(100000000, message: 'Le montant ne peut pas dépasser 1 000 000,00 €.')]
    private ?int $breakagePrice = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le montant ne peut pas être négatif.')]
    #[Assert\LessThanOrEqual(100000000, message: 'Le montant ne peut pas dépasser 1 000 000,00 €.')]
    private ?int $lossPrice = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $returnInstructions = null;

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

    public function getPricingType(): ?PricingType
    {
        return $this->pricingType;
    }

    public function setPricingType(?PricingType $pricingType): void
    {
        $this->pricingType = $pricingType;
    }

    public function getRefurbishDelay(): ?int
    {
        return $this->refurbishDelay;
    }

    public function setRefurbishDelay(?int $refurbishDelay): void
    {
        $this->refurbishDelay = $refurbishDelay;
    }

    public function getInstallPrice(): ?int
    {
        return $this->installPrice;
    }

    public function setInstallPrice(?int $installPrice): void
    {
        $this->installPrice = $installPrice;
    }

    public function getBreakagePrice(): ?int
    {
        return $this->breakagePrice;
    }

    public function setBreakagePrice(?int $breakagePrice): void
    {
        $this->breakagePrice = $breakagePrice;
    }

    public function getLossPrice(): ?int
    {
        return $this->lossPrice;
    }

    public function setLossPrice(?int $lossPrice): void
    {
        $this->lossPrice = $lossPrice;
    }

    public function getReturnInstructions(): ?string
    {
        return $this->returnInstructions;
    }

    public function setReturnInstructions(?string $returnInstructions): void
    {
        $this->returnInstructions = $returnInstructions;
    }
}
