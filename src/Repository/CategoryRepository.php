<?php

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /** @return Paginator<Category> */
    public function paginate(int $page, int $perPage): Paginator
    {
        $qb = $this->createQueryBuilder('c')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return new Paginator($qb);
    }

    public function nextPosition(): int
    {
        return 1 + (int) $this->createQueryBuilder('c')->select('MAX(c.position)')->getQuery()->getSingleScalarResult();
    }

    /** Voisine immédiate dans l'ordre d'affichage, ou null en tête / en fin de liste. */
    public function findNeighbor(Category $category, bool $before): ?Category
    {
        return $this->createQueryBuilder('c')
            ->where($before ? 'c.position < :position' : 'c.position > :position')
            ->setParameter('position', $category->getPosition())
            ->orderBy('c.position', $before ? 'DESC' : 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
