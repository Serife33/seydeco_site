<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Palier dégressif : à partir de `minQty` unités, le prix unitaire est `price` (centimes). */
#[ORM\Entity]
class PriceTier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'priceTiers')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    #[ORM\Column]
    #[Assert\NotNull(message: 'Saisir une quantité.')]
    #[Assert\Positive(message: 'La quantité doit être au moins 1.')]
    #[Assert\LessThanOrEqual(1000000, message: 'La quantité ne peut pas dépasser 1 000 000.')]
    private ?int $minQty = null;

    #[ORM\Column]
    #[Assert\NotNull(message: 'Saisir un prix.')]
    #[Assert\Positive(message: 'Le prix doit être supérieur à 0.')]
    #[Assert\LessThanOrEqual(100000000, message: 'Le montant ne peut pas dépasser 1 000 000,00 €.')]
    private ?int $price = null;

    public function __construct(?int $minQty = null, ?int $price = null)
    {
        $this->minQty = $minQty;
        $this->price = $price;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): void
    {
        $this->product = $product;
    }

    public function getMinQty(): ?int
    {
        return $this->minQty;
    }

    public function setMinQty(?int $minQty): void
    {
        $this->minQty = $minQty;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(?int $price): void
    {
        $this->price = $price;
    }
}
