<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /** @return Paginator<Product> */
    public function search(string $query, ?Category $category, int $page, int $perPage): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('c', 't', 'ph')
            ->join('p.category', 'c')
            // Prix « dès » et miniature de la liste, sans requête par produit
            ->leftJoin('p.priceTiers', 't')
            ->leftJoin('p.photos', 'ph')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        if ('' !== $query) {
            // La collation de la base ignore casse et accents
            $qb->andWhere('p.name LIKE :q')->setParameter('q', '%'.addcslashes($query, '%_\\').'%');
        }
        if ($category) {
            $qb->andWhere('p.category = :category')->setParameter('category', $category);
        }

        return new Paginator($qb);
    }
}
