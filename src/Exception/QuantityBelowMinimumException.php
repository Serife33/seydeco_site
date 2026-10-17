<?php

namespace App\Exception;

final class QuantityBelowMinimumException extends \DomainException
{
    public function __construct(public readonly int $quantity, public readonly int $minQty)
    {
        parent::__construct(\sprintf('Quantité %d inférieure au minimum de %d.', $quantity, $minQty));
    }
}
