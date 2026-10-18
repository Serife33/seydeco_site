<?php

namespace App\Service;

use App\Entity\Product;
use App\Entity\ProductPhoto;
use App\Entity\Realisation;
use App\Entity\RealisationPhoto;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Originaux des photos et vidéos, dans un dossier hors de public/.
 * Les fichiers remplacés ou retirés ne sont effacés qu'après un enregistrement réussi en base ;
 * en cas d'échec, abort() efface les fichiers envoyés pendant la requête.
 */
#[AsEntityListener(event: Events::postRemove, method: 'onPhotoRemoved', entity: ProductPhoto::class)]
#[AsEntityListener(event: Events::postRemove, method: 'onPhotoRemoved', entity: RealisationPhoto::class)]
#[AsDoctrineListener(event: Events::postFlush)]
final class MediaStorage
{
    /** @var list<string> fichiers rangés pendant cette requête */
    private array $stored = [];
    /** @var list<string> fichiers à effacer après le prochain flush réussi */
    private array $pending = [];

    public function __construct(
        #[Autowire('%app.upload_dir%')] private readonly string $dir,
        private readonly CacheManager $imageCache,
    ) {
    }

    /**
     * Nom aléatoire : jamais le nom envoyé par le navigateur.
     *
     * @throws FileException dossier non inscriptible, disque plein…
     */
    public function store(UploadedFile $file): string
    {
        $name = bin2hex(random_bytes(16)).'.'.($file->guessExtension() ?? 'bin');
        $file->move($this->dir, $name);
        $this->stored[] = $name;

        return $name;
    }

    public function discard(?string $name): void
    {
        if (null !== $name) {
            $this->pending[] = $name;
        }
    }

    public function onPhotoRemoved(ProductPhoto|RealisationPhoto $photo): void
    {
        $this->discard($photo->getFilename());
    }

    /**
     * Photos, vidéo et miniature envoyées avec le formulaire d'un produit ou d'une réalisation ;
     * les anciens fichiers remplacés ou retirés seront effacés après enregistrement.
     *
     * @throws FileException
     */
    public function storeForm(FormInterface $form, Product|Realisation $owner): void
    {
        foreach ($form->get('photos') as $row) {
            if ($file = $row->get('file')->getData()) {
                $row->getData()->setFilename($this->store($file));
            }
        }
        $video = $form->get('videoUpload')->getData();
        if ($video || $form->get('removeVideoFile')->getData()) {
            $this->discard($owner->getVideoFile());
            $owner->setVideoFile($video ? $this->store($video) : null);
        }
        $thumbnail = $form->get('videoThumbnailUpload')->getData();
        if ($thumbnail || $form->get('removeVideoThumbnail')->getData()) {
            $this->discard($owner->getVideoThumbnail());
            $owner->setVideoThumbnail($thumbnail ? $this->store($thumbnail) : null);
        }
    }

    public function postFlush(): void
    {
        array_map($this->delete(...), $this->pending);
        $this->pending = $this->stored = [];
    }

    /** Enregistrement raté : rien de l'ancien n'est effacé, les nouveaux fichiers le sont. */
    public function abort(): void
    {
        array_map($this->delete(...), $this->stored);
        $this->pending = $this->stored = [];
    }

    private function delete(string $name): void
    {
        (new Filesystem())->remove($this->dir.'/'.$name);
        $this->imageCache->remove($name);
    }
}
