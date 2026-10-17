<?php

namespace App\Controller;

use App\Repository\FaqItemRepository;
use App\Repository\PageRepository;
use App\Service\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Contenus de confiance : Comment ça marche, FAQ (story 2.4) et pages éditables (story 2.3). */
final class PageController extends AbstractController
{
    #[Route('/comment-ca-marche', name: 'how_it_works', methods: ['GET'])]
    public function howItWorks(SettingsProvider $s): Response
    {
        // Réglage vide ou invalide : null, la règle (ou sa partie) n'est pas affichée
        $number = fn (string $name) => ctype_digit($s->get($name)) ? (int) $s->get($name) : null;

        return $this->render('page/how_it_works.html.twig', ['rules' => [
            'depositPercent' => $number(SettingsProvider::DEPOSIT_PERCENT),
            'deposit' => $number(SettingsProvider::DEPOSIT_AMOUNT),
            'kmPrice' => $number(SettingsProvider::DELIVERY_PRICE_PER_KM),
            'minChairs' => $number(SettingsProvider::DELIVERY_MIN_CHAIRS),
            'maxKm' => $number(SettingsProvider::DELIVERY_MAX_KM),
            'depotCity' => trim($s->get(SettingsProvider::DEPOT_CITY)),
        ]]);
    }

    #[Route('/questions-frequentes', name: 'faq', methods: ['GET'])]
    public function faq(FaqItemRepository $items): Response
    {
        // Ordre de Serife ; un thème est placé à sa première question, les questions sans thème forment un groupe sans titre
        $themes = [];
        foreach ($items->findOrdered(visibleOnly: true) as $item) {
            $themes[$item->getTheme() ?? ''][] = $item;
        }

        return $this->render('page/faq.html.twig', ['themes' => $themes]);
    }

    /** Dernière route : /{slug} ne masque aucune autre adresse (slugs réservés dans SlugGenerator). */
    #[Route('/{slug}', name: 'page_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'], priority: -10)]
    public function show(string $slug, PageRepository $pages): Response
    {
        $page = $pages->findOneBy(['slug' => $slug]) ?? throw $this->createNotFoundException();

        // Page masquée : page normale, non indexée, jamais d'erreur
        return $this->render($page->isVisible() ? 'page/show.html.twig' : 'page/hidden.html.twig', ['page' => $page]);
    }
}
