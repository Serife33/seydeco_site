<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_product_slug', fields: ['slug'])]
class Product
{
    use VideoTrait;

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

    /** @var Collection<int, PriceTier> */
    #[ORM\OneToMany(targetEntity: PriceTier::class, mappedBy: 'product', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['minQty' => 'ASC'])]
    #[Assert\Valid]
    private Collection $priceTiers;

    /** Centimes ; prix de la table quand assez de chaises sont louées (tables rectangulaires : vide). */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'Le prix avec chaises doit être supérieur à 0.')]
    #[Assert\LessThanOrEqual(100000000, message: 'Le montant ne peut pas dépasser 1 000 000,00 €.')]
    private ?int $priceWithChairs = null;

    /** Centimes ; copié de la catégorie à la création, jamais écrasé ensuite. */
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

    /** @var Collection<int, ProductPhoto> */
    #[ORM\OneToMany(targetEntity: ProductPhoto::class, mappedBy: 'product', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    #[Assert\Valid]
    private Collection $photos;

    /** @var Collection<int, ProductColor> */
    #[ORM\OneToMany(targetEntity: ProductColor::class, mappedBy: 'product', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    #[Assert\Valid]
    private Collection $colors;

    /** @var Collection<int, RelatedProduct> « Souvent loué avec », 4 au plus, dans l'ordre choisi */
    #[ORM\OneToMany(targetEntity: RelatedProduct::class, mappedBy: 'product', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    #[Assert\Valid]
    #[Assert\Count(max: 4, maxMessage: 'Choisir 4 produits associés au plus.')]
    private Collection $relatedProducts;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private bool $visible = true;

    public function __construct()
    {
        $this->styles = new ArrayCollection();
        $this->eventTypes = new ArrayCollection();
        $this->priceTiers = new ArrayCollection();
        $this->photos = new ArrayCollection();
        $this->colors = new ArrayCollection();
        $this->relatedProducts = new ArrayCollection();
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

    /** @return Collection<int, PriceTier> */
    public function getPriceTiers(): Collection
    {
        return $this->priceTiers;
    }

    public function addPriceTier(PriceTier $tier): void
    {
        if (!$this->priceTiers->contains($tier)) {
            $tier->setProduct($this);
            $this->priceTiers->add($tier);
        }
    }

    public function removePriceTier(PriceTier $tier): void
    {
        $this->priceTiers->removeElement($tier);
    }

    /** Paliers : au moins un, le 1er égal à minQty, seuils strictement croissants (prix > 0 : sur PriceTier). */
    #[Assert\Callback]
    public function validatePriceTiers(ExecutionContextInterface $context): void
    {
        $thresholds = $this->priceTiers->map(fn (PriceTier $t) => $t->getMinQty())->getValues();
        if ([] === $thresholds) {
            $context->buildViolation('Ajouter au moins un palier de prix.')->atPath('priceTiers')->addViolation();

            return;
        }
        if (\in_array(null, $thresholds, true)) {
            return;
        }
        if (null !== $this->minQty && $thresholds[0] !== $this->minQty) {
            $context->buildViolation(\sprintf('Le premier palier doit commencer à %d, la quantité minimale.', $this->minQty))->atPath('priceTiers')->addViolation();
        }
        for ($i = 1, $n = \count($thresholds); $i < $n; ++$i) {
            if ($thresholds[$i] <= $thresholds[$i - 1]) {
                $context->buildViolation('Les quantités des paliers doivent aller en croissant, sans doublon.')->atPath('priceTiers')->addViolation();

                return;
            }
        }
    }

    public function getPriceWithChairs(): ?int
    {
        return $this->priceWithChairs;
    }

    public function setPriceWithChairs(?int $priceWithChairs): void
    {
        $this->priceWithChairs = $priceWithChairs;
    }

    #[Assert\Callback]
    public function validatePriceWithChairs(ExecutionContextInterface $context): void
    {
        if (null !== $this->priceWithChairs && null !== $this->category && PricingType::Tables !== $this->category->getPricingType()) {
            $context->buildViolation('Le prix avec chaises est réservé aux tables : laisser ce champ vide.')->atPath('priceWithChairs')->addViolation();
        }
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

    /** À la création seulement : les valeurs laissées vides reprennent celles de la catégorie. */
    public function applyCategoryDefaults(): void
    {
        $this->breakagePrice ??= $this->category?->getBreakagePrice();
        $this->lossPrice ??= $this->category?->getLossPrice();
        $this->returnInstructions ??= $this->category?->getReturnInstructions();
    }

    /** @return Collection<int, ProductPhoto> */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(ProductPhoto $photo): void
    {
        if (!$this->photos->contains($photo)) {
            $photo->setProduct($this);
            $this->photos->add($photo);
        }
    }

    public function removePhoto(ProductPhoto $photo): void
    {
        $this->photos->removeElement($photo);
    }

    /** @return Collection<int, ProductColor> */
    public function getColors(): Collection
    {
        return $this->colors;
    }

    public function addColor(ProductColor $color): void
    {
        if (!$this->colors->contains($color)) {
            $color->setProduct($this);
            $this->colors->add($color);
        }
    }

    public function removeColor(ProductColor $color): void
    {
        $this->colors->removeElement($color);
        foreach ($this->photos as $photo) {
            if ($photo->getColor() === $color) {
                $photo->setColor(null);
            }
        }
    }

    /** @return Collection<int, RelatedProduct> */
    public function getRelatedProducts(): Collection
    {
        return $this->relatedProducts;
    }

    public function addRelatedProduct(RelatedProduct $related): void
    {
        if (!$this->relatedProducts->contains($related)) {
            $related->setProduct($this);
            $this->relatedProducts->add($related);
        }
    }

    public function removeRelatedProduct(RelatedProduct $related): void
    {
        $this->relatedProducts->removeElement($related);
    }

    /** Ni le produit lui-même, ni deux fois le même produit. */
    #[Assert\Callback]
    public function validateRelatedProducts(ExecutionContextInterface $context): void
    {
        $seen = [];
        foreach ($this->relatedProducts as $i => $row) {
            $related = $row->getRelated();
            if (null === $related) {
                continue;
            }
            if ($related === $this) {
                $context->buildViolation('Un produit ne peut pas être associé à lui-même.')->atPath("relatedProducts[$i].related")->addViolation();
            } elseif (\in_array($related, $seen, true)) {
                $context->buildViolation('Ce produit est déjà choisi.')->atPath("relatedProducts[$i].related")->addViolation();
            }
            $seen[] = $related;
        }
    }
}
