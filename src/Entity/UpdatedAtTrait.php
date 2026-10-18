<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Date de dernière modification, tenue à jour à chaque enregistrement : `lastmod` du sitemap (story 2.12). */
trait UpdatedAtTrait
{
    #[ORM\Column(options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
