<?php

namespace App\Repository;

use App\Entity\DistanceCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DistanceCache>
 */
class DistanceCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DistanceCache::class);
    }

    public function meters(string $cacheKey): ?int
    {
        return $this->findOneBy(['cacheKey' => $cacheKey])?->getMeters();
    }

    /** Deux calculs simultanés de la même adresse : le second est ignoré (pas d'erreur d'unicité). */
    public function save(string $cacheKey, int $meters): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT IGNORE INTO distance_cache (cache_key, meters, created_at) VALUES (?, ?, ?)',
            [$cacheKey, $meters, (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
    }

    /** @return int nombre d'entrées supprimées */
    public function purgeOlderThan(\DateTimeImmutable $limit): int
    {
        return $this->createQueryBuilder('d')->delete()->where('d.createdAt < :limit')->setParameter('limit', $limit)->getQuery()->execute();
    }
}
