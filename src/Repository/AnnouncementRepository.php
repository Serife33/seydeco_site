<?php

namespace App\Repository;

use App\Entity\Announcement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Announcement>
 */
class AnnouncementRepository extends ServiceEntityRepository
{
    use OrderedRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Announcement::class);
    }

    /** @return list<Announcement> toutes, dans l'ordre du bandeau */
    public function findOrdered(): array
    {
        return $this->findBy([], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Annonces visibles dont la période inclut ce jour (bornes incluses) : filtre à l'affichage, aucune tâche planifiée.
     *
     * @return list<Announcement>
     */
    public function findActive(\DateTimeImmutable $today): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.visible = true AND e.startsOn <= :today AND e.endsOn >= :today')
            ->setParameter('today', $today->format('Y-m-d'))
            ->orderBy('e.position', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
