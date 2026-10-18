<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Ligne figée d'une version de devis (AD-8, AD-31) : libellé et prix copiés, jamais relus sur le produit. Montants en centimes. */
#[ORM\Entity]
class QuoteLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private QuoteVersion $version;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(length: 20, enumType: QuoteLineType::class)]
    private QuoteLineType $type;

    /** Produits masqués, jamais supprimés (AD-8) ; null pour les lignes hors produit. */
    #[ORM\ManyToOne]
    private ?Product $product = null;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $colorName = null;

    #[ORM\Column(nullable: true)]
    private ?int $quantity = null;

    #[ORM\Column(nullable: true)]
    private ?int $unitPrice = null;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(nullable: true)]
    private ?int $breakagePrice = null;

    #[ORM\Column(nullable: true)]
    private ?int $lossPrice = null;

    public function __construct(QuoteVersion $version, int $position, QuoteLineType $type, string $label, int $amount, ?int $quantity = null, ?int $unitPrice = null)
    {
        $this->version = $version;
        $this->position = $position;
        $this->type = $type;
        $this->label = mb_substr($label, 0, 255);
        $this->amount = $amount;
        $this->quantity = $quantity;
        $this->unitPrice = $unitPrice;
    }

    /** Ligne produit : produit, coloris et prix de casse/perte copiés au moment du devis. */
    public function copyProduct(Product $product, ?string $colorName): void
    {
        $this->product = $product;
        $this->colorName = $colorName;
        $this->breakagePrice = $product->getBreakagePrice();
        $this->lossPrice = $product->getLossPrice();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVersion(): QuoteVersion
    {
        return $this->version;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getType(): QuoteLineType
    {
        return $this->type;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getColorName(): ?string
    {
        return $this->colorName;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function getUnitPrice(): ?int
    {
        return $this->unitPrice;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getBreakagePrice(): ?int
    {
        return $this->breakagePrice;
    }

    public function getLossPrice(): ?int
    {
        return $this->lossPrice;
    }
}
