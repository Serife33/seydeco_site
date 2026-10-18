<?php

namespace App\Entity;

use App\Repository\DistanceCacheRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Distance par la route déjà demandée à Google (story 3.5, AD-10) : aucune adresse en clair, seulement l'empreinte
 * SHA-256 du dépôt + du lieu normalisés. Purgée après 13 mois par `app:gdpr:purge`.
 */
#[ORM\Entity(repositoryClass: DistanceCacheRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_distance_cache_key', columns: ['cache_key'])]
class DistanceCache
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $cacheKey;

    #[ORM\Column]
    private int $meters;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $cacheKey, int $meters, ?\DateTimeImmutable $createdAt = null)
    {
        $this->cacheKey = $cacheKey;
        $this->meters = $meters;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCacheKey(): string
    {
        return $this->cacheKey;
    }

    public function getMeters(): int
    {
        return $this->meters;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
