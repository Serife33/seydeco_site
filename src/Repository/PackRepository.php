<?php

namespace App\Repository;

use App\Entity\Pack;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Pack>
 */
class PackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pack::class);
    }

    /** @return Paginator<Pack> */
    public function paginate(int $page, int $perPage): Paginator
    {
        $qb = $this->createQueryBuilder('k')
            ->orderBy('k.position', 'ASC')
            ->addOrderBy('k.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return new Paginator($qb);
    }

    public function nextPosition(): int
    {
        return 1 + (int) $this->createQueryBuilder('k')->select('MAX(k.position)')->getQuery()->getSingleScalarResult();
    }

    /** Voisin immédiat dans l'ordre d'affichage, ou null en tête / en fin de liste. */
    public function findNeighbor(Pack $pack, bool $before): ?Pack
    {
        return $this->createQueryBuilder('k')
            ->where($before ? 'k.position < :position' : 'k.position > :position')
            ->setParameter('position', $pack->getPosition())
            ->orderBy('k.position', $before ? 'DESC' : 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Section Packs du catalogue : packs visibles ayant au moins un produit visible (d'une catégorie visible).
     *
     * @return list<Pack>
     */
    public function findPublic(): array
    {
        return $this->createQueryBuilder('k')
            ->andWhere('k.visible = true')
            ->andWhere('EXISTS (SELECT i.id FROM App\Entity\PackItem i JOIN i.product p JOIN p.category c WHERE i.pack = k AND p.visible = true AND c.visible = true)')
            ->orderBy('k.position', 'ASC')
            ->addOrderBy('k.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
