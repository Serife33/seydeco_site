<?php

namespace App\Controller;

use App\Entity\HomeHero;
use App\Repository\CategoryRepository;
use App\Repository\HomeHeroRepository;
use App\Repository\ProductRepository;
use App\Repository\RealisationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Accueil (story 2.10), et son aperçu avec le brouillon du haut de l'accueil (story 2.9). */
final class HomeController extends AbstractController
{
    public function __construct(
        private readonly HomeHeroRepository $heroes,
        private readonly CategoryRepository $categories,
        private readonly ProductRepository $products,
        private readonly RealisationRepository $realisations,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->home($this->heroes->published(), preview: false);
    }

    /** Sous /admin : réservé au back-office (pare-feu). « largeur=mobile » : l'aperçu dans un cadre de téléphone. */
    #[Route('/admin/haut-accueil/apercu', name: 'admin_home_hero_preview', methods: ['GET'])]
    public function preview(Request $request): Response
    {
        if ('mobile' === $request->query->getString('largeur')) {
            return $this->render('home/preview_mobile.html.twig');
        }

        return $this->home($this->heroes->findDraft() ?? $this->heroes->published(), preview: true);
    }

    private function home(HomeHero $hero, bool $preview): Response
    {
        return $this->render('home/index.html.twig', [
            'hero' => $hero,
            'preview' => $preview,
            'categories' => $this->categories->findBy(['visible' => true], ['position' => 'ASC', 'id' => 'ASC']),
            'featured' => $this->products->findFeatured(public: true),
            'realisations' => iterator_to_array($this->realisations->findPublic(3), false),
        ]);
    }
}
