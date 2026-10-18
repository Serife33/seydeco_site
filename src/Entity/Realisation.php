<?php

namespace App\Entity;

use App\Repository\RealisationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Événement équipé par Seydeco (story 2.5), photos et vidéo comme les produits. Se masque, ne s'efface jamais. */
#[ORM\Entity(repositoryClass: RealisationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_realisation_slug', fields: ['slug'])]
class Realisation
{
    use VideoTrait;

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

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Saisir la date de l\'événement.')]
    private ?\DateTimeImmutable $eventDate = null;

    /** Même liste que les produits. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?EventType $eventType = null;

    /** Lieu partenaire facultatif : son nom et sa ville sont recopiés à l'enregistrement (RealisationType). */
    #[ORM\ManyToOne]
    private ?Venue $venue = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Saisir le nom du lieu, ou choisir un lieu partenaire.')]
    #[Assert\Length(max: 150, maxMessage: 'Le nom du lieu ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $venueName = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Saisir la ville, ou choisir un lieu partenaire.')]
    #[Assert\Length(max: 100, maxMessage: 'La ville ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $city = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $text = null;

    /** @var Collection<int, RealisationPhoto> */
    #[ORM\OneToMany(targetEntity: RealisationPhoto::class, mappedBy: 'realisation', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    #[Assert\Valid]
    private Collection $photos;

    /** @var Collection<int, Product> produits utilisés ; les masqués ne sont pas affichés sur le site */
    #[ORM\ManyToMany(targetEntity: Product::class)]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $products;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $visible = true;

    public function __construct()
    {
        $this->photos = new ArrayCollection();
        $this->products = new ArrayCollection();
    }

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

    public function getEventDate(): ?\DateTimeImmutable
    {
        return $this->eventDate;
    }

    public function setEventDate(?\DateTimeImmutable $eventDate): void
    {
        $this->eventDate = $eventDate;
    }

    public function getEventType(): ?EventType
    {
        return $this->eventType;
    }

    public function setEventType(?EventType $eventType): void
    {
        $this->eventType = $eventType;
    }

    public function getVenue(): ?Venue
    {
        return $this->venue;
    }

    public function setVenue(?Venue $venue): void
    {
        $this->venue = $venue;
    }

    public function getVenueName(): ?string
    {
        return $this->venueName;
    }

    public function setVenueName(?string $venueName): void
    {
        $this->venueName = $venueName;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): void
    {
        $this->city = $city;
    }

    /** Lieu partenaire choisi : son nom et sa ville remplacent ceux saisis. */
    public function applyVenue(): void
    {
        if (null !== $this->venue) {
            $this->venueName = $this->venue->getName();
            $this->city = $this->venue->getCity();
        }
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): void
    {
        $this->text = $text;
    }

    /** @return Collection<int, RealisationPhoto> */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(RealisationPhoto $photo): void
    {
        if (!$this->photos->contains($photo)) {
            $photo->setRealisation($this);
            $this->photos->add($photo);
        }
    }

    public function removePhoto(RealisationPhoto $photo): void
    {
        $this->photos->removeElement($photo);
    }

    /** @return Collection<int, Product> */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): void
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
        }
    }

    public function removeProduct(Product $product): void
    {
        $this->products->removeElement($product);
    }

    /** @return list<Product> produits utilisés visibles sur le site (produit et catégorie visibles) */
    public function getPublicProducts(): array
    {
        return $this->products->filter(fn (Product $p) => $p->isVisible() && $p->getCategory()->isVisible())->getValues();
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
