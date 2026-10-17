<?php

namespace App\Repository;

use App\Entity\Style;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Style>
 */
class StyleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Style::class);
    }

    /** Doublon (casse et accents ignorés par la collation) : on réutilise l'existant. */
    public function findOrCreate(string $name): Style
    {
        $name = trim($name);
        $item = $this->findOneBy(['name' => $name]) ?? new Style($name);
        $this->getEntityManager()->persist($item);

        return $item;
    }
}
