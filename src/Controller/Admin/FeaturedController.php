<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Plus → Contenus → Les incontournables (story 2.10) : ordre des produits mis en avant sur l'accueil (cochés dans le Formulaire produit). */
#[Route('/admin/incontournables')]
final class FeaturedController extends AbstractController
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_featured_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/featured/index.html.twig', ['products' => $this->products->findFeatured(public: false)]);
    }

    #[Route('/{id}/monter', name: 'admin_featured_move_up', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveUp(Product $product, Request $request): Response
    {
        return $this->move($product, $request, before: true);
    }

    #[Route('/{id}/descendre', name: 'admin_featured_move_down', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveDown(Product $product, Request $request): Response
    {
        return $this->move($product, $request, before: false);
    }

    /** « Retirer de l'accueil » : le produit reste au catalogue. */
    #[Route('/{id}/retirer', name: 'admin_featured_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remove(Product $product, Request $request): Response
    {
        if ($this->tokenIsValid($product, $request)) {
            $product->setFeatured(false);
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_featured_index');
    }

    private function move(Product $product, Request $request, bool $before): Response
    {
        if ($this->tokenIsValid($product, $request) && $product->isFeatured() && $neighbor = $this->products->findFeaturedNeighbor($product, $before)) {
            $position = $product->getFeaturedPosition();
            $product->setFeaturedPosition($neighbor->getFeaturedPosition());
            $neighbor->setFeaturedPosition($position);
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_featured_index');
    }

    private function tokenIsValid(Product $product, Request $request): bool
    {
        if ($this->isCsrfTokenValid('featured'.$product->getId(), $request->getPayload()->getString('_token'))) {
            return true;
        }
        $this->addFlash('error', 'Session expirée, réessayez.');

        return false;
    }
}
