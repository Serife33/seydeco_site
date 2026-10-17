<?php

namespace App\Entity;

use App\Repository\PackRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Pack prêt à l'emploi (story 1.14) : éclaté en lignes produit à l'ajout au devis. Se masque, ne s'efface jamais. */
#[ORM\Entity(repositoryClass: PackRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_pack_slug', fields: ['slug'])]
class Pack
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

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Fichier dans le dossier d'envoi (hors de public/) ; rempli à l'enregistrement. */
    #[ORM\Column(length: 100)]
    private ?string $photo = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Décrivez la photo (ex. : table dressée pour 100 invités).')]
    #[Assert\Length(max: 255, maxMessage: 'La description ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $photoAlt = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $visible = true;

    /** @var Collection<int, PackItem> */
    #[ORM\OneToMany(targetEntity: PackItem::class, mappedBy: 'pack', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    #[Assert\Valid]
    #[Assert\Count(min: 1, minMessage: 'Ajouter au moins un produit.')]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
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

    /** @return Collection<int, PackItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(PackItem $item): void
    {
        if (!$this->items->contains($item)) {
            $item->setPack($this);
            $this->items->add($item);
        }
    }

    public function removeItem(PackItem $item): void
    {
        $this->items->removeElement($item);
    }

    /** @return list<PackItem> lignes dont le produit (ou sa catégorie) est masqué : signalées dans l'admin, absentes du site */
    public function getHiddenItems(): array
    {
        return $this->items->filter(fn (PackItem $i) => !$i->isProductPublic())->getValues();
    }

    #[Assert\Callback]
    public function validateUniqueProducts(ExecutionContextInterface $context): void
    {
        $seen = [];
        foreach ($this->items as $i => $item) {
            $id = $item->getProduct()?->getId();
            if (null === $id) {
                continue;
            }
            if (isset($seen[$id])) {
                $context->buildViolation('Ce produit est déjà dans le pack : modifier sa quantité plutôt.')->atPath("items[$i].product")->addViolation();
            }
            $seen[$id] = true;
        }
    }
}
