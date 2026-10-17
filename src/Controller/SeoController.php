<?php

namespace App\Controller;

use App\Service\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
}
