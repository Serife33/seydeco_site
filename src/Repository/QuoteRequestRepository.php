<?php

namespace App\Repository;

use App\Entity\QuoteRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuoteRequest>
 */
class QuoteRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuoteRequest::class);
    }

    /** Plus → Demandes (story 3.14) : les plus récentes en premier. @return Paginator<QuoteRequest> */
    public function paginate(int $page, int $perPage): Paginator
    {
        $qb = $this->createQueryBuilder('q')
            ->addSelect('v')
            ->leftJoin('q.currentVersion', 'v')
            ->orderBy('q.createdAt', 'DESC')
            ->addOrderBy('q.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return new Paginator($qb);
    }

    /** Pastille du back-office : demandes à l'état `nouvelle`. */
    public function countNew(): int
    {
        return $this->count(['status' => 'nouvelle']);
    }
}
