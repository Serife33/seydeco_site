<?php

namespace App\Controller;

use App\Repository\CategoryRepository;
use App\Repository\FaqItemRepository;
use App\Repository\PackRepository;
use App\Repository\PageRepository;
use App\Repository\ProductRepository;
use App\Repository\RealisationRepository;
use App\Repository\VenueRepository;
use App\Service\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SeoController extends AbstractController
{
    /** Fermé tant que « Site ouvert aux moteurs de recherche » est désactivé (story 2.2) ; ouvert, il pointe vers le sitemap (story 2.12). */
    #[Route('/robots.txt', name: 'robots', methods: ['GET'], format: 'txt')]
    public function robots(Request $request, SettingsProvider $settings): Response
    {
        $body = $settings->searchEnginesOpen()
            ? "User-agent: *\nDisallow: /admin\n\nSitemap: ".$request->getSchemeAndHttpHost()."/sitemap.xml\n"
            : "User-agent: *\nDisallow: /\n";

        return new Response($body, headers: ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** Généré à chaque appel depuis la base (AD-23) : seuls les éléments visibles ; un élément masqué en sort aussitôt. */
    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'], format: 'xml')]
    public function sitemap(
        PageRepository $pages,
        CategoryRepository $categories,
        ProductRepository $products,
        PackRepository $packs,
        RealisationRepository $realisations,
        VenueRepository $venues,
        FaqItemRepository $faq,
    ): Response {
        $url = fn (string $route, array $params = []) => $this->generateUrl($route, $params, UrlGeneratorInterface::ABSOLUTE_URL);
        $latest = fn (array $items) => $items ? max(array_map(fn ($item) => $item->getUpdatedAt(), $items)) : null;
        $visible = ['visible' => true];

        $categoryList = $categories->findBy($visible, ['position' => 'ASC', 'id' => 'ASC']);
        $productList = $products->createQueryBuilder('p')
            ->join('p.category', 'c')->addSelect('c')
            ->andWhere('p.visible = true')->andWhere('c.visible = true')
            ->orderBy('c.position')->addOrderBy('p.name')
            ->getQuery()->getResult();
        $realisationList = iterator_to_array($realisations->findPublic(10_000), false);
        $packList = $packs->findPublic();

        $urls = [];
        foreach ($categoryList as $category) {
            $urls[] = ['loc' => $url('catalog_category', ['slug' => $category->getSlug()]), 'lastmod' => $category->getUpdatedAt()];
        }
        foreach ($productList as $product) {
            $urls[] = ['loc' => $url('catalog_product', ['categorySlug' => $product->getCategory()->getSlug(), 'slug' => $product->getSlug()]), 'lastmod' => $product->getUpdatedAt()];
        }
        foreach ($packList as $pack) {
            $urls[] = ['loc' => $url('catalog_pack', ['slug' => $pack->getSlug()]), 'lastmod' => $pack->getUpdatedAt()];
        }
        foreach ($realisationList as $realisation) {
            $urls[] = ['loc' => $url('realisation_show', ['slug' => $realisation->getSlug()]), 'lastmod' => $realisation->getUpdatedAt()];
        }
        foreach ($venues->findBy($visible, ['position' => 'ASC', 'id' => 'ASC']) as $venue) {
            $urls[] = ['loc' => $url('venue_show', ['slug' => $venue->getSlug()]), 'lastmod' => $venue->getUpdatedAt()];
        }
        foreach ($pages->findOrdered(visibleOnly: true) as $page) {
            $urls[] = ['loc' => $url('page_show', ['slug' => $page->getSlug()]), 'lastmod' => $page->getUpdatedAt()];
        }
        // Listes : dernière modification de leurs éléments ; Comment ça marche (réglages) : sans date
        array_unshift(
            $urls,
            ['loc' => $url('catalog_index'), 'lastmod' => $latest(array_merge($categoryList, $packList))],
            ['loc' => $url('realisation_index'), 'lastmod' => $latest($realisationList)],
            ['loc' => $url('how_it_works'), 'lastmod' => null],
            ['loc' => $url('faq'), 'lastmod' => $latest($faq->findOrdered(visibleOnly: true))],
        );
        // Accueil : la plus récente de toutes
        $dates = array_filter(array_column($urls, 'lastmod'));
        array_unshift($urls, ['loc' => $url('app_home'), 'lastmod' => $dates ? max($dates) : null]);

        return $this->render('seo/sitemap.xml.twig', ['urls' => $urls]);
    }
}
