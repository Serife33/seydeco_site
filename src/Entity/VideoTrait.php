<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Vidéo facultative d'un produit ou d'une réalisation : fichier MP4 ou lien YouTube / Instagram / TikTok, miniature facultative. */
trait VideoTrait
{
    /** Fichier MP4 dans le dossier d'envoi ; exclusif avec `videoUrl`. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $videoFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'Le lien ne doit pas dépasser {{ limit }} caractères.')]
    #[Assert\Regex(
        pattern: '~^https://(www\.|m\.|vm\.)?(youtube\.com|youtu\.be|instagram\.com|tiktok\.com)/\S+$~i',
        message: 'Seuls les liens YouTube, Instagram ou TikTok sont acceptés (https://…).',
    )]
    private ?string $videoUrl = null;

    /** Image facultative ; sinon la photo principale sert de miniature. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $videoThumbnail = null;

    public function getVideoFile(): ?string
    {
        return $this->videoFile;
    }

    public function setVideoFile(?string $videoFile): void
    {
        $this->videoFile = $videoFile;
    }

    public function getVideoUrl(): ?string
    {
        return $this->videoUrl;
    }

    public function setVideoUrl(?string $videoUrl): void
    {
        $this->videoUrl = $videoUrl;
    }

    public function getVideoThumbnail(): ?string
    {
        return $this->videoThumbnail;
    }

    public function setVideoThumbnail(?string $videoThumbnail): void
    {
        $this->videoThumbnail = $videoThumbnail;
    }
}
