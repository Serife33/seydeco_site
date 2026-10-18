<?php

namespace App\Entity;

use App\Repository\HomeHeroRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Haut de l'accueil (story 2.9) : une ligne publiée et, pendant une modification, une ligne brouillon.
 * « Publier » recopie le brouillon dans la version publiée. Une ou deux photos, pas de vidéo (vitesse).
 */
#[ORM\Entity(repositoryClass: HomeHeroRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_home_hero_draft', fields: ['draft'])]
class HomeHero
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $draft = false;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank(message: 'Saisir un titre.', normalizer: 'trim')]
    #[Assert\Length(max: 60, maxMessage: 'Le titre ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $title = 'Location de matériel de réception';

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank(message: 'Saisir un texte.', normalizer: 'trim')]
    #[Assert\Length(max: 160, maxMessage: 'Le texte ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $text = 'Chaises, tables, art de la table, nappes et décoration à louer pour vos mariages et réceptions.';

    #[ORM\Column(length: 40)]
    #[Assert\NotBlank(message: 'Saisir le texte du bouton.', normalizer: 'trim')]
    #[Assert\Length(max: 40, maxMessage: 'Le texte du bouton ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $buttonLabel = 'Voir le catalogue';

    /** Adresse d'une page du site uniquement (« /location ») ; « // » et « /\ » refusés (autre site déguisé). */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Saisir l\'adresse d\'une page du site, par exemple /location.', normalizer: 'trim')]
    #[Assert\Length(max: 255, maxMessage: 'L\'adresse ne doit pas dépasser {{ limit }} caractères.')]
    #[Assert\Regex(pattern: '~^/(?![/\\\\])\S*$~', message: 'Saisir l\'adresse d\'une page du site, par exemple /location (pas de lien vers un autre site).')]
    private ?string $buttonLink = '/location';

    /** Fichiers dans le dossier d'envoi (hors de public/). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $photo = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'La description ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $photoAlt = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $photo2 = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'La description ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $photo2Alt = null;

    /** Brouillon, copie de la version publiée (mêmes fichiers, partagés). */
    public static function draftOf(self $published): self
    {
        $draft = new self();
        $draft->copyFrom($published);
        $draft->draft = true;

        return $draft;
    }

    public function copyFrom(self $other): void
    {
        [$this->title, $this->text, $this->buttonLabel, $this->buttonLink, $this->photo, $this->photoAlt, $this->photo2, $this->photo2Alt]
            = [$other->title, $other->text, $other->buttonLabel, $other->buttonLink, $other->photo, $other->photoAlt, $other->photo2, $other->photo2Alt];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = null === $title ? null : trim($title);
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): void
    {
        $this->text = null === $text ? null : trim($text);
    }

    public function getButtonLabel(): ?string
    {
        return $this->buttonLabel;
    }

    public function setButtonLabel(?string $buttonLabel): void
    {
        $this->buttonLabel = null === $buttonLabel ? null : trim($buttonLabel);
    }

    public function getButtonLink(): ?string
    {
        return $this->buttonLink;
    }

    public function setButtonLink(?string $buttonLink): void
    {
        $this->buttonLink = null === $buttonLink ? null : trim($buttonLink);
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

    public function getPhoto2(): ?string
    {
        return $this->photo2;
    }

    public function setPhoto2(?string $photo2): void
    {
        $this->photo2 = $photo2;
    }

    public function getPhoto2Alt(): ?string
    {
        return $this->photo2Alt;
    }

    public function setPhoto2Alt(?string $photo2Alt): void
    {
        $this->photo2Alt = $photo2Alt;
    }

    /** @return list<string> fichiers utilisés */
    public function getFiles(): array
    {
        return array_values(array_filter([$this->photo, $this->photo2]));
    }
}
