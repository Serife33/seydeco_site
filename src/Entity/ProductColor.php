<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Coloris : nom écrit + couleur de la pastille. */
#[ORM\Entity]
class ProductColor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'colors')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank(message: 'Saisir le nom du coloris.')]
    #[Assert\Length(max: 60, maxMessage: 'Le nom ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $name = null;

    #[ORM\Column(length: 7)]
    #[Assert\NotBlank(message: 'Choisir la couleur de la pastille.')]
    #[Assert\CssColor(formats: Assert\CssColor::HEX_LONG, message: 'Choisir une couleur valide, par exemple #FFFFFF.')]
    private ?string $hex = '#FFFFFF';

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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getHex(): ?string
    {
        return $this->hex;
    }

    public function setHex(?string $hex): void
    {
        $this->hex = $hex;
    }
}
