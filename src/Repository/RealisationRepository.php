<?php

namespace App\Repository;

use App\Entity\Realisation;
use App\Entity\Venue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Realisation>
 */
class RealisationRepository extends ServiceEntityRepository
{
    use OrderedRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Realisation::class);
    }

    /** @return Paginator<Realisation> réalisations visibles dans l'ordre de Serife, les $limit premières (« Voir plus ») */
    public function findPublic(int $limit, ?Venue $venue = null): Paginator
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.visible = true')
            ->orderBy('e.position', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->setMaxResults($limit);
        if ($venue) {
            $qb->andWhere('e.venue = :venue')->setParameter('venue', $venue);
        }

        return new Paginator($qb);
    }
}
