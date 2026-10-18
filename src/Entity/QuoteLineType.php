<?php

namespace App\Entity;

/** Types de ligne d'un devis (AD-31) : seules les lignes `product` ont un produit et une quantité ; `toll` arrive en Epic 4. */
enum QuoteLineType: string
{
    case Product = 'product';
    case Discount = 'discount';
    case Delivery = 'delivery';
    case Pickup = 'pickup';
    case Installation = 'installation';
    case Cleaning = 'cleaning';
    case Toll = 'toll';
}
