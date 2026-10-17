<?php

namespace App\Controller;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Service\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogController extends AbstractController
{
    private const int PER_PAGE = 24;

    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly ProductRepository $products,
        private readonly SettingsProvider $settings,
    ) {
    }

    #[Route('/location', name: 'catalog_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('catalog/index.html.twig', [
            'categories' => $this->categories->findBy(['visible' => true], ['position' => 'ASC', 'id' => 'ASC']),
        ]);
    }

    #[Route('/recherche', name: 'catalog_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        return $this->listing($request, 'catalog/search.html.twig', null, $this->filters($request));
    }

    #[Route('/location/{slug}', name: 'catalog_category', methods: ['GET'])]
    public function category(string $slug, Request $request): Response
    {
        $category = $this->categories->findOneBy(['slug' => $slug]) ?? throw $this->createNotFoundException();
        if (!$category->isVisible()) {
            // Ancienne URL d'une catégorie masquée : page normale, jamais d'erreur
            return $this->render('catalog/hidden_category.html.twig', ['category' => $category]);
        }

        return $this->listing($request, 'catalog/category.html.twig', $category, $this->filters($request));
    }

    /** @param array{q: string, categories: list<string>, colors: list<string>, styles: list<int>, events: list<int>} $filters */
    private function listing(Request $request, string $template, ?Category $category, array $filters): Response
    {
        $sort = $request->query->getString('tri');
        $sort = isset(ProductRepository::SORTS[$sort]) ? $sort : 'populaires';
        $facets = $this->products->publicFacets($category);
        $allCategories = $category ? [] : $this->categories->findBy(['visible' => true], ['position' => 'ASC', 'id' => 'ASC']);
        // Valeurs inconnues (catégorie masquée, id périmé, autre casse) : ramenées aux choix affichés, sinon ignorées
        $keep = function (array $values, array $options): array {
            $byKey = array_combine(array_map(fn ($o) => mb_strtolower((string) $o), $options), $options);

            return array_values(array_unique(array_filter(array_map(fn ($v) => $byKey[mb_strtolower((string) $v)] ?? null, $values), fn ($v) => null !== $v)));
        };
        $filters['categories'] = $keep($filters['categories'], array_map(fn (Category $c) => $c->getSlug(), $allCategories));
        $filters['colors'] = $keep($filters['colors'], $facets['colors']);
        $filters['styles'] = $keep($filters['styles'], array_map(fn ($s) => $s->getId(), $facets['styles']));
        $filters['events'] = $keep($filters['events'], array_map(fn ($e) => $e->getId(), $facets['events']));
        $activeFilters = \count($filters['categories']) + \count($filters['colors']) + \count($filters['styles']) + \count($filters['events']);
        // Recherche sans mot exploitable (vide, « a », « !! ») ni filtre : retour au catalogue
        if (!$category && '' === $filters['q'] && 0 === $activeFilters) {
            return $this->redirectToRoute('catalog_index');
        }
        // « Voir plus » : la page N affiche les N × 24 premiers produits
        $page = min(max(1, $request->query->getInt('page', 1)), 1_000);
        $products = $this->products->findPublic($category, $filters, $sort, $page * self::PER_PAGE);
        $total = \count($products);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $pages) {
            $products = $this->products->findPublic($category, $filters, $sort, ($page = $pages) * self::PER_PAGE);
        }

        return $this->render($template, [
            'category' => $category,
            'products' => $products,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'filters' => $filters,
            'activeFilters' => $activeFilters,
            'sort' => $sort,
            'sorts' => ProductRepository::SORTS,
            'facets' => $facets,
            'allCategories' => $allCategories,
            'whatsapp' => $this->settings->whatsappNumber(),
        ]);
    }

    /** @return array{q: string, categories: list<string>, colors: list<string>, styles: list<int>, events: list<int>} */
    private function filters(Request $request): array
    {
        // Scalaire (?couleur=Blanc, lien tapé à la main) ou liste ; 50 valeurs au plus
        $strings = fn (string $key) => \array_slice(array_values(array_unique(array_filter(array_map('strval', array_filter((array) ($request->query->all()[$key] ?? []), 'is_scalar'))))), 0, 50);
        $ids = fn (string $key) => array_values(array_unique(array_filter(array_map('intval', $strings($key)), fn (int $id) => $id > 0)));
        $q = mb_substr(trim($request->query->getString('q')), 0, 100);

        return [
            // Aucun mot exploitable (« a », « !! ») = recherche vide
            'q' => ProductRepository::searchTerms($q) ? $q : '',
            'categories' => $strings('categorie'),
            'colors' => $strings('couleur'),
            'styles' => $ids('style'),
            'events' => $ids('evenement'),
        ];
    }
}
