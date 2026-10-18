<?php

namespace App\Repository;

use Doctrine\ORM\Tools\Pagination\Paginator;

/** Contenus ordonnés par Serife (« Monter » / « Descendre ») : liste admin paginée, position suivante, voisin. */
trait OrderedRepositoryTrait
{
    public function paginate(int $page, int $perPage): Paginator
    {
        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.position', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return new Paginator($qb);
    }

    public function nextPosition(): int
    {
        return 1 + (int) $this->createQueryBuilder('e')->select('MAX(e.position)')->getQuery()->getSingleScalarResult();
    }

    /** Voisin immédiat dans l'ordre d'affichage, ou null en tête / en fin de liste. */
    public function findNeighbor(object $item, bool $before): ?object
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
