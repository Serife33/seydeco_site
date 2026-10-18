<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Photo d'une réalisation ; la position 0 est la photo principale. Fichier original hors de public/. */
#[ORM\Entity]
class RealisationPhoto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'photos')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Realisation $realisation = null;

    /** Nom du fichier dans le dossier d'envoi ; rempli à l'enregistrement. */
    #[ORM\Column(length: 100)]
    private ?string $filename = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Décrivez la photo (ex. : table d\'honneur fleurie sous le chapiteau).')]
    #[Assert\Length(max: 255, maxMessage: 'La description ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $alt = null;

    #[ORM\Column]
    private int $position = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRealisation(): ?Realisation
    {
        return $this->realisation;
    }

    public function setRealisation(?Realisation $realisation): void
    {
        $this->realisation = $realisation;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(?string $filename): void
    {
        $this->filename = $filename;
    }

    public function getAlt(): ?string
    {
        return $this->alt;
    }

    public function setAlt(?string $alt): void
    {
        $this->alt = $alt;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(?int $position): void
    {
        $this->position = (int) $position;
    }
}
