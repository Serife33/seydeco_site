<?php

namespace App\Service;

/** Distance par la route du dépôt (réglage `depot_address`) au lieu (story 3.5, AD-10). */
interface DistanceCalculatorInterface
{
    /** Mètres entiers, ou null si la distance est inconnue (adresse introuvable, erreur, délai, quota, limite). */
    public function metersFromDepot(string $address): ?int;
}
