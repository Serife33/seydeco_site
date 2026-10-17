<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Produit d'un pack et sa quantité proposée (au moins la quantité minimale du produit). */
#[ORM\Entity]
class PackItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Pack $pack = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Choisir un produit.')]
    private ?Product $product = null;

    #[ORM\Column]
    #[Assert\NotNull(message: 'Saisir une quantité.')]
    #[Assert\Positive(message: 'La quantité doit être au moins 1.')]
    #[Assert\LessThanOrEqual(1000000, message: 'La quantité ne peut pas dépasser 1 000 000.')]
    private ?int $quantity = null;

    public function __construct(?Product $product = null, ?int $quantity = null)
    {
        $this->product = $product;
        $this->quantity = $quantity;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPack(): ?Pack
    {
        return $this->pack;
    }

    public function setPack(?Pack $pack): void
    {
        $this->pack = $pack;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): void
    {
        $this->product = $product;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(?int $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function isProductPublic(): bool
    {
        return null !== $this->product && $this->product->isVisible() && $this->product->getCategory()?->isVisible();
    }

    #[Assert\Callback]
    public function validateMinimum(ExecutionContextInterface $context): void
    {
        $min = $this->product?->getMinQty();
        if (null !== $min && null !== $this->quantity && $this->quantity > 0 && $this->quantity < $min) {
            $context->buildViolation(\sprintf('Minimum %d pour « %s » (sa quantité minimale).', $min, $this->product->getName()))->atPath('quantity')->addViolation();
        }
    }
}
