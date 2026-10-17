<?php

namespace App\Repository;

use App\Entity\FaqItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FaqItem>
 */
class FaqItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FaqItem::class);
    }

    /** @return list<FaqItem> dans l'ordre d'affichage (visibles seulement si $visibleOnly) */
    public function findOrdered(bool $visibleOnly = false): array
    {
        return $this->findBy($visibleOnly ? ['visible' => true] : [], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function nextPosition(): int
    {
        return 1 + (int) $this->createQueryBuilder('e')->select('MAX(e.position)')->getQuery()->getSingleScalarResult();
    }

    /** Voisin immédiat dans l'ordre d'affichage, ou null en tête / en fin de liste. */
    public function findNeighbor(FaqItem $item, bool $before): ?FaqItem
    {
        return $this->createQueryBuilder('e')
            ->where($before ? 'e.position < :position' : 'e.position > :position')
            ->setParameter('position', $item->getPosition())
            ->orderBy('e.position', $before ? 'DESC' : 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
