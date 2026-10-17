<?php

namespace App\Entity;

/** Seule source pour reconnaître chaises et tables (minimum, remise, seuils de livraison). */
enum PricingType: string
{
    case Chairs = 'chairs';
    case Tables = 'tables';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Chairs => 'Chaises',
            self::Tables => 'Tables',
            self::Other => 'Autre',
        };
    }
}
