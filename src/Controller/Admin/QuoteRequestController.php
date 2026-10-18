<?php

namespace App\Controller\Admin;

use App\Repository\QuoteRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Plus → Demandes (story 3.14) : liste et fiche en lecture seule ; les actions arrivent en Epic 4. */
#[Route('/admin/demandes')]
final class QuoteRequestController extends AbstractController
{
    private const int PER_PAGE = 20;

    public function __construct(private readonly QuoteRequestRepository $requests)
    {
    }

    #[Route('', name: 'admin_quote_request_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = min(max(1, $request->query->getInt('page', 1)), 10_000);
        $requests = $this->requests->paginate($page, self::PER_PAGE);
        $pages = max(1, (int) ceil(\count($requests) / self::PER_PAGE));
        if ($page > $pages) {
            $requests = $this->requests->paginate($page = $pages, self::PER_PAGE);
        }

        return $this->render('admin/quote_request/index.html.twig', ['requests' => $requests, 'page' => $page, 'pages' => $pages]);
    }

    #[Route('/{id}', name: 'admin_quote_request_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $quote = $this->requests->find($id);

        return $this->render('admin/quote_request/show.html.twig', ['quote' => $quote], new Response(status: $quote ? 200 : 404));
    }
}
