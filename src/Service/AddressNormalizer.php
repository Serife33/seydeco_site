<?php

namespace App\Service;

use function Symfony\Component\String\u;

/**
 * Forme unique d'une adresse pour la comparer (AD-10) : dépôt, lieu de l'estimation et de la demande.
 * « 12, Rue de l'Église  33530 BASSENS » → « 12 rue de l eglise 33530 bassens ».
 */
final class AddressNormalizer
{
    public function normalize(string $address): string
    {
        return u($address)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();
    }
}
