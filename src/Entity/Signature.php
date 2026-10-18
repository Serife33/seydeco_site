<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Signature d'une version de devis (story 3.16, AD-29) : tracé, date, IP et PDF signé rendu une fois, stocké hors de public/
 * avec son SHA-256. « Signé » se déduit de sa présence, jamais stocké. Une signature en ligne par version (contrainte unique).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_signature_version_type', columns: ['version_id', 'type'])]
class Signature
{
    public const string TYPE_ONLINE = 'online';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'signatures')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private QuoteVersion $version;

    #[ORM\Column(length: 10)]
    private string $type;

    #[ORM\Column]
    private \DateTimeImmutable $signedAt;

    #[ORM\Column(length: 45)]
    private string $ip;

    /** Tracé PNG en base64. */
    #[ORM\Column(type: Types::TEXT, length: 16777215)]
    private string $image;

    /** Chemin relatif au dossier des envois (`signatures/…pdf`). */
    #[ORM\Column(length: 100)]
    private string $pdfPath;

    #[ORM\Column(length: 64)]
    private string $pdfSha256;

    public function __construct(QuoteVersion $version, string $type, \DateTimeImmutable $signedAt, string $ip, string $image, string $pdfPath, string $pdfSha256)
    {
        $this->version = $version;
        $this->type = $type;
        $this->signedAt = $signedAt;
        $this->ip = $ip;
        $this->image = $image;
        $this->pdfPath = $pdfPath;
        $this->pdfSha256 = $pdfSha256;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVersion(): QuoteVersion
    {
        return $this->version;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSignedAt(): \DateTimeImmutable
    {
        return $this->signedAt;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getImage(): string
    {
        return $this->image;
    }

    public function getPdfPath(): string
    {
        return $this->pdfPath;
    }

    public function getPdfSha256(): string
    {
        return $this->pdfSha256;
    }
}
