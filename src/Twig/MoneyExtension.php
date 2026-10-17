<?php

namespace App\Twig;

use App\Entity\Product;
use App\Service\PricingCalculator;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final class MoneyExtension
{
    public function __construct(private readonly PricingCalculator $pricing)
    {
    }

    /** Seul filtre d'affichage des montants : 123450 → « 1 234,50 € » (espaces insécables) ; `short` : 100 → « 1 € ». */
    #[AsTwigFilter('money')]
    public function money(int $cents, bool $short = false): string
    {
        return number_format($cents / 100, $short && 0 === $cents % 100 ? 0 : 2, ',', "\u{A0}")."\u{A0}€";
    }

    /** Prix « dès » en centimes (calculé par PricingCalculator, jamais dans Twig). */
    #[AsTwigFunction('from_price')]
    public function fromPrice(Product $product): ?int
    {
        return $this->pricing->fromPrice($product);
    }
}
