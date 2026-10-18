<?php

namespace App\Entity;

use App\Repository\VenueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Lieu partenaire (story 2.6), page /lieux/{slug} avec ses réalisations. Se masque, ne s'efface jamais. */
#[ORM\Entity(repositoryClass: VenueRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_venue_slug', fields: ['slug'])]
class Venue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Saisir un nom.')]
    #[Assert\Length(max: 150, maxMessage: 'Le nom ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $name = null;

    /** Généré à la création puis figé. */
    #[ORM\Column(length: 170)]
    private ?string $slug = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Saisir la ville.')]
    #[Assert\Length(max: 100, maxMessage: 'La ville ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $city = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'L\'adresse ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $address = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $text = null;

    /** Fichier dans le dossier d'envoi (hors de public/) ; rempli à l'enregistrement. */
    #[ORM\Column(length: 100)]
    private ?string $photo = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Décrivez la photo (ex. : salle de réception du château).')]
    #[Assert\Length(max: 255, maxMessage: 'La description ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $photoAlt = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'Le lien ne doit pas dépasser {{ limit }} caractères.')]
    #[Assert\Url(message: 'Saisir une adresse complète, par exemple https://www.chateau-exemple.fr.', requireTld: true)]
    private ?string $website = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $visible = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function initSlug(string $slug): void
    {
        if (null !== $this->slug) {
            throw new \LogicException('Le slug est figé après la création.');
        }
        $this->slug = $slug;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): void
    {
        $this->city = $city;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): void
    {
        $this->address = $address;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): void
    {
        $this->text = $text;
    }

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function setPhoto(?string $photo): void
    {
        $this->photo = $photo;
    }

    public function getPhotoAlt(): ?string
    {
        return $this->photoAlt;
    }

    public function setPhotoAlt(?string $photoAlt): void
    {
        $this->photoAlt = $photoAlt;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): void
    {
        $this->website = $website;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function setVisible(bool $visible): void
    {
        $this->visible = $visible;
    }
}
