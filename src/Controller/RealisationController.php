<?php

namespace App\Controller;

use App\Repository\RealisationRepository;
use App\Repository\VenueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Réalisations (story 2.5) et lieux partenaires (story 2.6). */
final class RealisationController extends AbstractController
{
    private const int PER_PAGE = 12;

    public function __construct(
        private readonly RealisationRepository $realisations,
        private readonly VenueRepository $venues,
    ) {
    }

    #[Route('/realisations', name: 'realisation_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // « Voir plus » : la page N affiche les N × 12 premières réalisations (sans JavaScript)
        $page = min(max(1, $request->query->getInt('page', 1)), 1_000);
        $realisations = $this->realisations->findPublic($page * self::PER_PAGE);

        return $this->render('realisation/index.html.twig', [
            'realisations' => iterator_to_array($realisations, false),
            'more' => \count($realisations) > $page * self::PER_PAGE,
            'page' => $page,
            'venues' => $this->venues->findBy(['visible' => true], ['position' => 'ASC', 'id' => 'ASC']),
        ]);
    }

    #[Route('/realisations/{slug}', name: 'realisation_show', methods: ['GET'])]
    public function show(string $slug, #[Autowire('%app.upload_dir%')] string $uploadDir): Response
    {
        $realisation = $this->realisations->findOneBy(['slug' => $slug]) ?? throw $this->createNotFoundException();
        if (!$realisation->isVisible()) {
            // Ancienne URL d'une réalisation masquée : page normale, non indexée, jamais d'erreur
            return $this->render('realisation/hidden.html.twig', ['realisation' => $realisation]);
        }

        return $this->render('realisation/show.html.twig', [
            'realisation' => $realisation,
            'video' => CatalogController::video($realisation, $uploadDir),
        ]);
    }

    /** Vidéo déposée en fichier (original hors de public/), lue par le lecteur de la page. */
    #[Route('/realisations/{slug}/video', name: 'realisation_video', methods: ['GET'])]
    public function video(string $slug, #[Autowire('%app.upload_dir%')] string $uploadDir): BinaryFileResponse
    {
        $realisation = $this->realisations->findOneBy(['slug' => $slug]);
        $file = $realisation?->getVideoFile();
        if (null === $file || !$realisation->isVisible() || !is_file($uploadDir.'/'.$file)) {
            throw $this->createNotFoundException();
        }

        return new BinaryFileResponse($uploadDir.'/'.$file, headers: ['Content-Type' => 'video/mp4']);
    }

    #[Route('/lieux/{slug}', name: 'venue_show', methods: ['GET'])]
    public function venue(string $slug): Response
    {
        $venue = $this->venues->findOneBy(['slug' => $slug]) ?? throw $this->createNotFoundException();
        if (!$venue->isVisible()) {
            return $this->render('venue/hidden.html.twig', ['venue' => $venue]);
        }

        return $this->render('venue/show.html.twig', [
            'venue' => $venue,
            // shortcut: toutes les réalisations du lieu sur une page, à paginer si un lieu en compte des dizaines
            'realisations' => iterator_to_array($this->realisations->findPublic(1_000, $venue), false),
        ]);
    }
}
