<?php

namespace App\Repository;

use App\Entity\Review;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    use OrderedRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /** @return list<Review> dans l'ordre d'affichage (visibles seulement si $visibleOnly) */
    public function findOrdered(bool $visibleOnly = false): array
    {
        return $this->findBy($visibleOnly ? ['visible' => true] : [], ['position' => 'ASC', 'id' => 'ASC']);
    }
}
