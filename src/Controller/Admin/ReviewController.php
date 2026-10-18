<?php

namespace App\Controller\Admin;

use App\Entity\Review;
use App\Form\ReviewType;
use App\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Plus → Contenus → Avis (story 2.8). Aucune suppression : un avis se masque. */
#[Route('/admin/avis')]
final class ReviewController extends AbstractController
{
    public function __construct(
        private readonly ReviewRepository $reviews,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_review_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/review/index.html.twig', ['reviews' => $this->reviews->findOrdered()]);
    }

    #[Route('/ajouter', name: 'admin_review_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $review = new Review();
        $form = $this->createForm(ReviewType::class, $review)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $review->setPosition($this->reviews->nextPosition());
            $this->em->persist($review);

            return $this->saved();
        }

        return $this->render('admin/review/form.html.twig', ['form' => $form, 'review' => $review]);
    }

    #[Route('/{id}/modifier', name: 'admin_review_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Review $review, Request $request): Response
    {
        $form = $this->createForm(ReviewType::class, $review)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            return $this->saved();
        }

        return $this->render('admin/review/form.html.twig', ['form' => $form, 'review' => $review]);
    }

    #[Route('/{id}/monter', name: 'admin_review_move_up', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveUp(Review $review, Request $request): Response
    {
        return $this->move($review, $request, before: true);
    }

    #[Route('/{id}/descendre', name: 'admin_review_move_down', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveDown(Review $review, Request $request): Response
    {
        return $this->move($review, $request, before: false);
    }

    #[Route('/{id}/visibilite', name: 'admin_review_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleVisibility(Review $review, Request $request): Response
    {
        if ($this->tokenIsValid($review, $request)) {
            $review->setVisible(!$review->isVisible());
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_review_index');
    }

    private function saved(): Response
    {
        $this->em->flush();
        $this->addFlash('success', 'Avis enregistré.');

        return $this->redirectToRoute('admin_review_index');
    }

    private function move(Review $review, Request $request, bool $before): Response
    {
        if ($this->tokenIsValid($review, $request) && $neighbor = $this->reviews->findNeighbor($review, $before)) {
            $position = $review->getPosition();
            $review->setPosition($neighbor->getPosition());
            $neighbor->setPosition($position);
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_review_index');
    }

    private function tokenIsValid(Review $review, Request $request): bool
    {
        if ($this->isCsrfTokenValid('review'.$review->getId(), $request->getPayload()->getString('_token'))) {
            return true;
        }
        $this->addFlash('error', 'Session expirée, réessayez.');

        return false;
    }
}
