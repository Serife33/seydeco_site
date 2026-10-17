<?php

namespace App\Service;

use Doctrine\ORM\EntityRepository;
use Symfony\Component\String\Slugger\SluggerInterface;

/** Slug unique dans la table : « chaises », puis « chaises-2 », « chaises-3 »… */
final class SlugGenerator
{
    public function __construct(private readonly SluggerInterface $slugger)
    {
    }

    /** @param EntityRepository<object> $repository */
    public function generate(string $name, EntityRepository $repository): string
    {
        // Place gardée pour le suffixe « -N » : le slug tient toujours dans sa colonne
        $max = $repository->getClassMetadata()->getFieldMapping('slug')->length - 6;
        $base = $this->slugger->slug($name)->lower()->truncate($max)->trimEnd('-')->toString() ?: 'element';
        $slug = $base;
        // « packs » réservé : /location/packs/… mène aux pages des packs (story 1.14)
        for ($i = 2; 'packs' === $slug || null !== $repository->findOneBy(['slug' => $slug]); ++$i) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
