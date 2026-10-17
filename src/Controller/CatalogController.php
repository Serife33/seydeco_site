<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\PricingType;
use App\Repository\CategoryRepository;
use App\Repository\PackRepository;
use App\Repository\ProductRepository;
use App\Service\PricingCalculator;
use App\Service\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
        private readonly PricingCalculator $pricing,
        private readonly PackRepository $packs,
    ) {
    }

    #[Route('/location', name: 'catalog_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('catalog/index.html.twig', [
            'categories' => $this->categories->findBy(['visible' => true], ['position' => 'ASC', 'id' => 'ASC']),
            'packs' => $this->packs->findPublic(),
        ]);
    }

    /** Avant la route produit (même forme d'URL). Produits masqués retirés ; quantités proposées, ajustables dans la page. */
    #[Route('/location/packs/{slug}', name: 'catalog_pack', methods: ['GET'], priority: 1)]
    public function pack(string $slug): Response
    {
        $pack = $this->packs->findOneBy(['slug' => $slug]) ?? throw $this->createNotFoundException();
        $items = [];
        foreach ($pack->isVisible() ? $pack->getItems() : [] as $item) {
            if (!$item->isProductPublic()) {
                continue;
            }
            $product = $item->getProduct();
            // Minimum relevé depuis la création du pack : la quantité proposée suit
            $qty = max($item->getQuantity(), $product->getMinQty() ?? 1);
            // Produit sans palier (saisie ancienne) : ligne gardée, sans prix
            $price = $product->getPriceTiers()->isEmpty() ? null : $this->pricing->unitPrice($product, $qty);
            $items[] = ['product' => $product, 'qty' => $qty, 'price' => $price, 'tiers' => $this->tierRows($product)];
        }
        if (!$items) {
            // Pack masqué ou sans produit visible : page normale, jamais d'erreur
            return $this->render('catalog/hidden_pack.html.twig', ['pack' => $pack]);
        }

        return $this->render('catalog/pack.html.twig', ['pack' => $pack, 'items' => $items]);
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

    #[Route('/location/{categorySlug}/{slug}', name: 'catalog_product', methods: ['GET'])]
    public function product(string $categorySlug, string $slug, #[Autowire('%app.upload_dir%')] string $uploadDir): Response
    {
        $product = $this->products->findOneBy(['slug' => $slug]) ?? throw $this->createNotFoundException();
        $category = $product->getCategory();
        if ($category->getSlug() !== $categorySlug) {
            return $this->redirectToRoute('catalog_product', ['categorySlug' => $category->getSlug(), 'slug' => $slug], Response::HTTP_MOVED_PERMANENTLY);
        }
        if (!$product->isVisible() || !$category->isVisible()) {
            // Ancienne URL d'un produit masqué : page normale avec les produits visibles de la catégorie, sans prix
            return $this->render('catalog/hidden_product.html.twig', [
                'product' => $product,
                'products' => $this->products->findPublic($category, ['q' => '', 'categories' => [], 'colors' => [], 'styles' => [], 'events' => []], 'populaires', self::PER_PAGE),
            ]);
        }

        $s = $this->settings;
        // Réglage vide ou invalide : null, la ligne (ou sa partie) n'est pas affichée
        $number = fn (string $name) => ctype_digit($s->get($name)) ? (int) $s->get($name) : null;

        return $this->render('catalog/product.html.twig', [
            'product' => $product,
            'category' => $category,
            'tiers' => $this->tierRows($product),
            'related' => $this->products->findPublicRelated([$product->getId()])[$product->getId()] ?? [],
            'chairsNote' => PricingType::Chairs === $category->getPricingType() && $this->products->hasPublicTableWithChairsPrice(),
            'video' => $this->video($product, $uploadDir),
            'whatsapp' => $s->whatsappNumber(),
            'info' => [
                'deposit' => $number(SettingsProvider::DEPOSIT_AMOUNT),
                'kmPrice' => $number(SettingsProvider::DELIVERY_PRICE_PER_KM),
                'minChairs' => $number(SettingsProvider::DELIVERY_MIN_CHAIRS),
                'maxKm' => $number(SettingsProvider::DELIVERY_MAX_KM),
                'depotCity' => trim($s->get(SettingsProvider::DEPOT_CITY)),
            ],
        ]);
    }

    /**
     * Paliers « 15 à 49 », « 50 et plus » : prix unitaire calculé par PricingCalculator.
     *
     * @return list<array{from: int, to: int|null, price: int}>
     */
    private function tierRows(Product $product): array
    {
        $tiers = $product->getPriceTiers()->getValues();
        $minQty = $product->getMinQty() ?? 1;
        $rows = [];
        foreach ($tiers as $i => $tier) {
            $from = max($tier->getMinQty(), $minQty);
            $next = $tiers[$i + 1] ?? null;
            $rows[] = ['from' => $from, 'to' => $next ? $next->getMinQty() - 1 : null, 'price' => $this->pricing->unitPrice($product, $from)];
        }

        return $rows;
    }

    /** Vidéo déposée en fichier (original hors de public/), lue par le lecteur de la fiche. */
    #[Route('/location/{categorySlug}/{slug}/video', name: 'catalog_product_video', methods: ['GET'])]
    public function productVideo(string $categorySlug, string $slug, #[Autowire('%app.upload_dir%')] string $uploadDir): BinaryFileResponse
    {
        $product = $this->products->findOneBy(['slug' => $slug]);
        $file = $product?->getVideoFile();
        $category = $product?->getCategory();
        if (null === $file || $category->getSlug() !== $categorySlug || !$product->isVisible() || !$category->isVisible() || !is_file($uploadDir.'/'.$file)) {
            throw $this->createNotFoundException();
        }

        return new BinaryFileResponse($uploadDir.'/'.$file, headers: ['Content-Type' => 'video/mp4']);
    }

    /** @return array{type: 'file'}|array{type: 'youtube', id: string}|array{type: 'link', url: string, provider: string}|null */
    private function video(Product $product, string $uploadDir): ?array
    {
        $file = $product->getVideoFile();
        if ($file) {
            // Fichier absent du disque : pas de lecteur cassé
            return is_file($uploadDir.'/'.$file) ? ['type' => 'file'] : null;
        }
        $url = $product->getVideoUrl();
        if (null === $url) {
            return null;
        }
        if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?(?:\S*&)?v=|shorts/|embed/|live/))([\w-]{11})~i', $url, $m)) {
            return ['type' => 'youtube', 'id' => $m[1]];
        }
        $host = parse_url($url, \PHP_URL_HOST) ?: '';
        $provider = match (true) {
            str_contains($host, 'instagram') => 'Instagram',
            str_contains($host, 'tiktok') => 'TikTok',
            default => 'YouTube',
        };

        // Instagram, TikTok, lien YouTube non reconnu : ouverture dans un nouvel onglet, aucun script tiers
        return ['type' => 'link', 'url' => $url, 'provider' => $provider];
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
        // Une seule exécution de la requête (le Paginator la relance à chaque parcours)
        $products = iterator_to_array($products, false);

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
            // Liens « Souvent loué avec » des confirmations d'ajout des cartes, en une requête
            'relatedLinks' => $this->settings->quoteOnline() ? $this->products->findPublicRelated(array_map(fn (Product $p) => $p->getId(), $products)) : [],
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
