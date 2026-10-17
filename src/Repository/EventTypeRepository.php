<?php

namespace App\Repository;

use App\Entity\EventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventType>
 */
class EventTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventType::class);
    }

    /** Doublon (casse et accents ignorés par la collation) : on réutilise l'existant. */
    public function findOrCreate(string $name): EventType
    {
        $name = trim($name);
        $item = $this->findOneBy(['name' => $name]) ?? new EventType($name);
        $this->getEntityManager()->persist($item);

        return $item;
    }
}
