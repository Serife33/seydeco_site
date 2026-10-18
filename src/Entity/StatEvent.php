<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Événement statistique anonyme (AD-20) : aucune clé étrangère ni donnée personnelle, conservé après suppression d'une demande.
 * Écrit seulement par les listeners du workflow `quote_request`. Une seule fois par demande et par type.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_stat_event', columns: ['stat_key', 'type'])]
class StatEvent
{
    public const string QUOTE_CREATED = 'devis_cree';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $statKey;

    #[ORM\Column(length: 30)]
    private string $type;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /** « Comment nous avez-vous connus ? » (clé de QuoteRequest::SOURCES). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $source;

    #[ORM\Column]
    private bool $pro;

    #[ORM\Column(nullable: true)]
    private ?int $amount;

    /** @var list<array{id: int|null, name: string, quantity: int}>|null lignes produit anonymes */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $products;

    /** @param list<array{id: int|null, name: string, quantity: int}>|null $products */
    public function __construct(string $statKey, string $type, \DateTimeImmutable $occurredAt, ?string $source, bool $pro, ?int $amount = null, ?array $products = null)
    {
        $this->statKey = $statKey;
        $this->type = $type;
        $this->occurredAt = $occurredAt;
        $this->source = $source;
        $this->pro = $pro;
        $this->amount = $amount;
        $this->products = $products;
    }

    public function getStatKey(): string
    {
        return $this->statKey;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function isPro(): bool
    {
        return $this->pro;
    }

    public function getAmount(): ?int
    {
        return $this->amount;
    }

    /** @return list<array{id: int|null, name: string, quantity: int}>|null */
    public function getProducts(): ?array
    {
        return $this->products;
    }
}
