<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\EventType;
use App\Entity\PricingType;
use App\Entity\Product;
use App\Entity\RelatedProduct;
use App\Entity\Style;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /** @return Paginator<Product> */
    public function search(string $query, ?Category $category, int $page, int $perPage): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('c', 't', 'ph')
            ->join('p.category', 'c')
            // Prix « dès » et miniature de la liste, sans requête par produit
            ->leftJoin('p.priceTiers', 't')
            ->leftJoin('p.photos', 'ph')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        if ('' !== $query) {
            // La collation de la base ignore casse et accents
            $qb->andWhere('p.name LIKE :q')->setParameter('q', '%'.addcslashes($query, '%_\\').'%');
        }
        if ($category) {
            $qb->andWhere('p.category = :category')->setParameter('category', $category);
        }

        return new Paginator($qb);
    }

    /** Tris publics : clé dans l'URL → libellé. */
    public const array SORTS = ['populaires' => 'Les plus loués', 'prix' => 'Prix croissant', 'nouveautes' => 'Nouveautés'];

    /**
     * Catalogue public : produits visibles d'une catégorie visible, recherche + filtres + tri, les `$limit` premiers.
     *
     * @param array{q: string, categories: list<string>, colors: list<string>, styles: list<int>, events: list<int>} $filters
     *
     * @return Paginator<Product>
     */
    public function findPublic(?Category $category, array $filters, string $sort, int $limit): Paginator
    {
        $qb = $this->publicQuery($category)
            ->addSelect('c', 't', 'ph')
            // Catégorie (lien de la carte), prix « dès » et photo principale sans requête par produit
            ->leftJoin('p.priceTiers', 't')
            ->leftJoin('p.photos', 'ph')
            ->setMaxResults($limit);

        // Chaque mot doit apparaître dans le nom, la description, la catégorie ou un coloris (collation : ni casse ni accents)
        foreach (self::searchTerms($filters['q']) as $i => $term) {
            $qb->andWhere("p.name LIKE :t$i OR p.description LIKE :t$i OR c.name LIKE :t$i OR EXISTS (SELECT qc$i.id FROM App\Entity\ProductColor qc$i WHERE qc$i.product = p AND qc$i.name LIKE :t$i)")
                ->setParameter("t$i", $term);
        }
        if ($filters['categories']) {
            $qb->andWhere('c.slug IN (:categories)')->setParameter('categories', $filters['categories']);
        }
        if ($filters['colors']) {
            $qb->andWhere('EXISTS (SELECT fc.id FROM App\Entity\ProductColor fc WHERE fc.product = p AND fc.name IN (:colors))')->setParameter('colors', $filters['colors']);
        }
        if ($filters['styles']) {
            $qb->andWhere('p.id IN (SELECT fs_p.id FROM App\Entity\Product fs_p JOIN fs_p.styles fs WHERE fs.id IN (:styles))')->setParameter('styles', $filters['styles']);
        }
        if ($filters['events']) {
            $qb->andWhere('p.id IN (SELECT fe_p.id FROM App\Entity\Product fe_p JOIN fe_p.eventTypes fe WHERE fe.id IN (:events))')->setParameter('events', $filters['events']);
        }

        match ($sort) {
            // Prix « dès » = plus bas palier, comme PricingCalculator::fromPrice()
            // Produits sans palier (pas de prix « dès ») en dernier
            'prix' => $qb->addSelect('CASE WHEN EXISTS (SELECT np.id FROM App\Entity\PriceTier np WHERE np.product = p) THEN 0 ELSE 1 END AS HIDDEN noPrice')
                ->addSelect('(SELECT MIN(sp.price) FROM App\Entity\PriceTier sp WHERE sp.product = p) AS HIDDEN fromPrice')
                ->orderBy('noPrice', 'ASC')->addOrderBy('fromPrice', 'ASC'),
            'nouveautes' => $qb->orderBy('p.createdAt', 'DESC'),
            // shortcut: « Les plus loués » = ordre des catégories puis nom, à remplacer quand les stats de location existeront
            default => $qb->orderBy('c.position', 'ASC'),
        };
        $qb->addOrderBy('p.name', 'ASC')->addOrderBy('p.id', 'ASC');

        return new Paginator($qb);
    }

    /**
     * Valeurs proposées dans les filtres : seulement celles des produits visibles de la portée.
     *
     * @return array{colors: list<string>, styles: list<Style>, events: list<EventType>}
     */
    public function publicFacets(?Category $category): array
    {
        $em = $this->getEntityManager();
        $ids = fn (string $relation) => $this->publicQuery($category)->select('DISTINCT r.id')->join("p.$relation", 'r')->getQuery()->getSingleColumnResult();

        return [
            'colors' => $this->publicQuery($category)->select('DISTINCT pc.name')->join('p.colors', 'pc')->orderBy('pc.name')->getQuery()->getSingleColumnResult(),
            'styles' => $em->getRepository(Style::class)->findBy(['id' => $ids('styles')], ['name' => 'ASC']),
            'events' => $em->getRepository(EventType::class)->findBy(['id' => $ids('eventTypes')], ['name' => 'ASC']),
        ];
    }

    /** Au moins une table visible a un prix avec chaises (rappel sur la fiche des chaises). */
    public function hasPublicTableWithChairsPrice(): bool
    {
        return null !== $this->publicQuery(null)
            ->select('p.id')
            ->andWhere('c.pricingType = :tables')->setParameter('tables', PricingType::Tables)
            ->andWhere('p.priceWithChairs IS NOT NULL')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * « Souvent loué avec » de plusieurs produits en une requête : seuls les associés visibles (catégorie visible), dans l'ordre choisi.
     *
     * @param list<int> $productIds
     *
     * @return array<int, list<Product>> id du produit → associés
     */
    public function findPublicRelated(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('r', 'rel', 'relc')
            ->from(RelatedProduct::class, 'r')
            ->join('r.related', 'rel')
            ->join('rel.category', 'relc')
            ->andWhere('r.product IN (:ids)')->setParameter('ids', $productIds)
            ->andWhere('rel.visible = true')
            ->andWhere('relc.visible = true')
            ->orderBy('r.position', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()->getResult();
        $map = [];
        foreach ($rows as $row) {
            $map[$row->getProduct()->getId()][] = $row->getRelated();
        }

        return $map;
    }

    private function publicQuery(?Category $category): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.category', 'c')
            ->andWhere('p.visible = true')
            ->andWhere('c.visible = true');
        if ($category) {
            $qb->andWhere('p.category = :category')->setParameter('category', $category);
        }

        return $qb;
    }

    /**
     * Motifs LIKE, un par mot ; pluriel simple retiré (« Chaises » → chaise, « plateaux » → plateau).
     *
     * @return list<string>
     */
    public static function searchTerms(string $query): array
    {
        $terms = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, \PREG_SPLIT_NO_EMPTY) as $word) {
            if (mb_strlen($word) > 3) {
                $word = preg_replace('/[sx]$/u', '', $word);
            }
            if (mb_strlen($word) >= 2) {
                $terms[] = '%'.$word.'%';
            }
        }

        // Borné : une requête de 200 mots ne doit pas produire 200 conditions
        return \array_slice(array_values(array_unique($terms)), 0, 8);
    }
}
