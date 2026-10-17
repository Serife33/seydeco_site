<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** « Souvent loué avec » (story 1.15) : produit associé, choisi par Serife, dans l'ordre d'affichage. */
#[ORM\Entity]
class RelatedProduct
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'relatedProducts')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Choisir un produit.')]
    private ?Product $related = null;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(?Product $related = null)
    {
        $this->related = $related;
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

    public function getRelated(): ?Product
    {
        return $this->related;
    }

    public function setRelated(?Product $related): void
    {
        $this->related = $related;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(?int $position): void
    {
        $this->position = (int) $position;
    }
}
