<?php

namespace App\Repository;

use App\Entity\HomeHero;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HomeHero>
 */
class HomeHeroRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HomeHero::class);
    }

    /** Version publiée ; avant la première publication, les textes par défaut sans photo (non enregistrés). */
    public function published(): HomeHero
    {
        return $this->findOneBy(['draft' => false]) ?? new HomeHero();
    }

    public function findDraft(): ?HomeHero
    {
        return $this->findOneBy(['draft' => true]);
    }
}
