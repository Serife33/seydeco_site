<?php

namespace App\Service;

use Doctrine\ORM\EntityRepository;
use Symfony\Component\String\Slugger\SluggerInterface;

/** Slug unique dans la table : « chaises », puis « chaises-2 », « chaises-3 »… */
final class SlugGenerator
{
    /**
     * Adresses déjà prises : /location/packs/… (packs, story 1.14) et les routes du site,
     * qu'une page servie sur /{slug} (story 2.3) ne doit jamais masquer.
     */
    private const array RESERVED = ['packs', 'location', 'recherche', 'mon-devis', 'admin', 'comment-ca-marche', 'questions-frequentes', 'realisations'];

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
        for ($i = 2; \in_array($slug, self::RESERVED, true) || null !== $repository->findOneBy(['slug' => $slug]); ++$i) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
