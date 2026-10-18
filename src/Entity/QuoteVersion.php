<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Version immuable d'un devis (AD-8, AD-28) : période copiée (jamais de lien vers Slot), lignes et contexte de calcul figés.
 * Toute modification crée la version suivante (Epic 4). Montants en centimes.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_quote_version_number', columns: ['request_id', 'number'])]
class QuoteVersion
{
    public const string MODE_PICKUP = 'pickup';
    public const string MODE_DELIVERY = 'delivery';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'versions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private QuoteRequest $request;

    /** 1 pour la première version ; v2, v3… ensuite (AD-25). */
    #[ORM\Column]
    private int $number;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $eventDate;

    #[ORM\Column]
    private \DateTimeImmutable $startAt;

    #[ORM\Column]
    private \DateTimeImmutable $endAt;

    #[ORM\Column(length: 10)]
    private string $mode;

    /** Livraison : Serife reprend le matériel (sinon retour au dépôt). */
    #[ORM\Column]
    private bool $collect;

    #[ORM\Column]
    private bool $installation;

    #[ORM\Column]
    private bool $cleaning;

    /** Adresse du lieu, en livraison seulement. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address;

    /** Contexte de calcul et conditions figés à la création (AD-8). */
    #[ORM\Column(type: Types::JSON)]
    private array $pricingContext;

    #[ORM\Column]
    private int $total;

    /** Acompte demandé ; le reste à payer n'est jamais stocké (AD-30). */
    #[ORM\Column]
    private int $deposit;

    /** Total hors livraison : distance inconnue, frais confirmés par Serife. */
    #[ORM\Column]
    private bool $excludesDelivery;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, QuoteLine> */
    #[ORM\OneToMany(targetEntity: QuoteLine::class, mappedBy: 'version', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    /** @var Collection<int, Signature> */
    #[ORM\OneToMany(targetEntity: Signature::class, mappedBy: 'version')]
    private Collection $signatures;

    /** @param array<string, mixed> $pricingContext */
    public function __construct(
        QuoteRequest $request,
        int $number,
        \DateTimeImmutable $eventDate,
        \DateTimeImmutable $startAt,
        \DateTimeImmutable $endAt,
        string $mode,
        bool $collect,
        bool $installation,
        bool $cleaning,
        ?string $address,
        array $pricingContext,
        int $total,
        int $deposit,
        bool $excludesDelivery,
    ) {
        $this->request = $request;
        $this->number = $number;
        $this->eventDate = $eventDate;
        $this->startAt = $startAt;
        $this->endAt = $endAt;
        $this->mode = $mode;
        $this->collect = $collect;
        $this->installation = $installation;
        $this->cleaning = $cleaning;
        $this->address = $address;
        $this->pricingContext = $pricingContext;
        $this->total = $total;
        $this->deposit = $deposit;
        $this->excludesDelivery = $excludesDelivery;
        $this->createdAt = new \DateTimeImmutable();
        $this->lines = new ArrayCollection();
        $this->signatures = new ArrayCollection();
    }

    public function addLine(QuoteLine $line): void
    {
        $this->lines->add($line);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): QuoteRequest
    {
        return $this->request;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getEventDate(): \DateTimeImmutable
    {
        return $this->eventDate;
    }

    public function getStartAt(): \DateTimeImmutable
    {
        return $this->startAt;
    }

    public function getEndAt(): \DateTimeImmutable
    {
        return $this->endAt;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function isDelivery(): bool
    {
        return self::MODE_DELIVERY === $this->mode;
    }

    public function isCollect(): bool
    {
        return $this->collect;
    }

    public function isInstallation(): bool
    {
        return $this->installation;
    }

    public function isCleaning(): bool
    {
        return $this->cleaning;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    /** @return array<string, mixed> */
    public function getPricingContext(): array
    {
        return $this->pricingContext;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getDeposit(): int
    {
        return $this->deposit;
    }

    public function excludesDelivery(): bool
    {
        return $this->excludesDelivery;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, QuoteLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    /** Signature en ligne de cette version ; « signé » s'en déduit (AD-29). */
    public function getSignature(): ?Signature
    {
        return $this->signatures->findFirst(fn (int $i, Signature $s) => Signature::TYPE_ONLINE === $s->getType());
    }

    public function addSignature(Signature $signature): void
    {
        $this->signatures->add($signature);
    }
}
