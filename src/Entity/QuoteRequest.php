<?php

namespace App\Entity;

use App\Repository\QuoteRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Demande de devis d'une cliente (story 3.12, AD-18, AD-20) : coordonnées, consentements horodatés, jeton secret du lien,
 * clé anonyme des statistiques. La période et les montants vivent dans `currentVersion` (AD-28).
 * Statut porté par le workflow `quote_request` seulement (AD-6) : aucun setter.
 */
#[ORM\Entity(repositoryClass: QuoteRequestRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_quote_request_number', columns: ['number'])]
#[ORM\UniqueConstraint(name: 'uniq_quote_request_token', columns: ['token'])]
class QuoteRequest
{
    public const string PHONE_INVALID = 'Saisissez un numéro de téléphone valide, par exemple 06 12 34 56 78.';
    public const string EMAIL_MISSING = 'Ce champ est nécessaire pour vous envoyer le lien du devis.';
    public const string EMAIL_INVALID = 'Saisissez une adresse email valide, par exemple ines@exemple.fr.';
    public const string SIRET_INVALID = 'Le SIRET compte 14 chiffres.';
    /** « Comment nous avez-vous connus ? » : clé enregistrée → libellé. */
    public const array SOURCES = [
        'instagram' => 'Instagram',
        'tiktok' => 'TikTok',
        'google' => 'Google',
        'bouche-a-oreille' => 'Bouche-à-oreille',
        'lieu-partenaire' => 'Lieu partenaire',
        'autre' => 'Autre',
    ];

    /** Places du workflow `quote_request` → Badge statut du back-office. */
    public const array STATUS_LABELS = [
        'nouvelle' => 'Nouvelle',
        'validee' => 'Validée',
        'relancee' => 'Relancée',
        'reservee' => 'Réservée',
        'confirmee' => 'Confirmée',
        'terminee' => 'Terminée',
        'expiree' => 'Expirée',
        'annulee' => 'Annulée',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** SDDEV-AAAAMMNNN, attribué par DocumentNumberer dans la transaction de création (AD-25). */
    #[ORM\Column(length: 20)]
    private ?string $number = null;

    /** Lien secret /devis/{jeton} : 128 bits aléatoires, jamais l'id ni le numéro (AD-18). */
    #[ORM\Column(length: 32)]
    private string $token;

    /** Clé des statistiques anonymes (AD-20), sans lien réversible. */
    #[ORM\Column(length: 32)]
    private string $statKey;

    #[ORM\Column(length: 20)]
    private ?string $status = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Indiquez votre prénom.')]
    #[Assert\Length(max: 80, maxMessage: 'Le prénom ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $firstName = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Indiquez votre nom.')]
    #[Assert\Length(max: 80, maxMessage: 'Le nom ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $lastName = null;

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank(message: 'Indiquez votre téléphone.')]
    #[Assert\Regex(pattern: '/^\+?(?:[\s.\-]?\d){9,14}$/', message: self::PHONE_INVALID)]
    private ?string $phone = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: self::EMAIL_MISSING)]
    #[Assert\Email(message: self::EMAIL_INVALID)]
    #[Assert\Length(max: 180, maxMessage: 'L\'adresse email ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $email = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150, maxMessage: 'La raison sociale ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $companyName = null;

    #[ORM\Column(length: 14, nullable: true)]
    #[Assert\Regex(pattern: '/^\d{14}$/', message: self::SIRET_INVALID)]
    private ?string $siret = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'L\'adresse de facturation ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $billingAddress = null;

    /** « Accès particulier » : étage, escalier, horaires du lieu… */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000, maxMessage: 'Ce texte ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $access = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Choice(choices: ['instagram', 'tiktok', 'google', 'bouche-a-oreille', 'lieu-partenaire', 'autre'], message: 'Choisissez une réponse de la liste.')]
    private ?string $source = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $termsAcceptedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $privacyAcceptedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?QuoteVersion $currentVersion = null;

    /** @var Collection<int, QuoteVersion> */
    #[ORM\OneToMany(targetEntity: QuoteVersion::class, mappedBy: 'request', cascade: ['persist'])]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $versions;

    public function __construct()
    {
        $this->token = bin2hex(random_bytes(16));
        $this->statKey = bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable();
        $this->versions = new ArrayCollection();
    }

    /** Une seule fois, dans la transaction de création. */
    public function assignNumber(string $number): void
    {
        if (null !== $this->number) {
            throw new \LogicException('Le numéro d\'un devis ne change jamais.');
        }
        $this->number = $number;
    }

    /** Les deux cases cochées au moment de l'envoi (story 3.11). */
    public function acceptConsents(\DateTimeImmutable $at): void
    {
        $this->termsAcceptedAt = $at;
        $this->privacyAcceptedAt = $at;
    }

    /** Nouvelle version en vigueur (AD-28). */
    public function addVersion(QuoteVersion $version): void
    {
        $this->versions->add($version);
        $this->currentVersion = $version;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getStatKey(): string
    {
        return $this->statKey;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): void
    {
        $this->firstName = self::clean($firstName);
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): void
    {
        $this->lastName = self::clean($lastName);
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = self::clean($phone);
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = self::clean($email);
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function setCompanyName(?string $companyName): void
    {
        $this->companyName = self::clean($companyName);
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    /** Espaces retirés : « 123 456 789 00012 » est accepté. */
    public function setSiret(?string $siret): void
    {
        $this->siret = self::clean(null === $siret ? null : preg_replace('/\s+/u', '', $siret));
    }

    public function getBillingAddress(): ?string
    {
        return $this->billingAddress;
    }

    public function setBillingAddress(?string $billingAddress): void
    {
        $this->billingAddress = self::clean($billingAddress);
    }

    public function getAccess(): ?string
    {
        return $this->access;
    }

    public function setAccess(?string $access): void
    {
        $this->access = self::clean($access);
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): void
    {
        $this->source = self::clean($source);
    }

    /** Professionnel : raison sociale ou SIRET renseigné. */
    public function isPro(): bool
    {
        return null !== $this->companyName || null !== $this->siret;
    }

    public function getTermsAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->termsAcceptedAt;
    }

    public function getPrivacyAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->privacyAcceptedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCurrentVersion(): ?QuoteVersion
    {
        return $this->currentVersion;
    }

    /** @return Collection<int, QuoteVersion> */
    public function getVersions(): Collection
    {
        return $this->versions;
    }

    private static function clean(?string $value): ?string
    {
        return null === $value ? null : (trim($value) ?: null);
    }
}
