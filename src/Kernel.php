<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // Fuseau unique pour le web, la console et les tests, quel que soit le php.ini du serveur
        date_default_timezone_set('Europe/Paris');

        parent::boot();
    }
}
