<?php

namespace App\Repository;

use App\Entity\DepotClosure;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DepotClosure>
 */
class DepotClosureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DepotClosure::class);
    }

    /** Une fermeture couvre-t-elle ce jour (bornes incluses) ? */
    public function isClosedOn(\DateTimeImmutable $day): bool
    {
        return (bool) $this->createQueryBuilder('c')->select('COUNT(c.id)')
            ->where('c.startsOn <= :day AND c.endsOn >= :day')
            ->setParameter('day', $day->format('Y-m-d'))
            ->getQuery()->getSingleScalarResult();
    }
}
