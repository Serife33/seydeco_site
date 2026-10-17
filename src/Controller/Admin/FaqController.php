<?php

namespace App\Controller\Admin;

use App\Entity\FaqItem;
use App\Form\FaqItemType;
use App\Repository\FaqItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Plus → Contenus → FAQ (story 2.4). Aucune suppression : une question se masque. */
#[Route('/admin/faq')]
final class FaqController extends AbstractController
{
    public function __construct(
        private readonly FaqItemRepository $items,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_faq_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/faq/index.html.twig', ['items' => $this->items->findOrdered()]);
    }

    #[Route('/ajouter', name: 'admin_faq_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $item = new FaqItem();
        $form = $this->createForm(FaqItemType::class, $item)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $item->setPosition($this->items->nextPosition());
            $this->em->persist($item);

            return $this->saved();
        }

        return $this->render('admin/faq/form.html.twig', ['form' => $form, 'item' => $item]);
    }

    #[Route('/{id}/modifier', name: 'admin_faq_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(FaqItem $item, Request $request): Response
    {
        $form = $this->createForm(FaqItemType::class, $item)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            return $this->saved();
        }

        return $this->render('admin/faq/form.html.twig', ['form' => $form, 'item' => $item]);
    }

    #[Route('/{id}/monter', name: 'admin_faq_move_up', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveUp(FaqItem $item, Request $request): Response
    {
        return $this->move($item, $request, before: true);
    }

    #[Route('/{id}/descendre', name: 'admin_faq_move_down', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveDown(FaqItem $item, Request $request): Response
    {
        return $this->move($item, $request, before: false);
    }

    #[Route('/{id}/visibilite', name: 'admin_faq_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleVisibility(FaqItem $item, Request $request): Response
    {
        if ($this->tokenIsValid($item, $request)) {
            $item->setVisible(!$item->isVisible());
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_faq_index');
    }

    private function saved(): Response
    {
        $this->em->flush();
        $this->addFlash('success', 'Question enregistrée.');

        return $this->redirectToRoute('admin_faq_index');
    }

    private function move(FaqItem $item, Request $request, bool $before): Response
    {
        if ($this->tokenIsValid($item, $request) && $neighbor = $this->items->findNeighbor($item, $before)) {
            $position = $item->getPosition();
            $item->setPosition($neighbor->getPosition());
            $neighbor->setPosition($position);
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_faq_index');
    }

    private function tokenIsValid(FaqItem $item, Request $request): bool
    {
        if ($this->isCsrfTokenValid('faq'.$item->getId(), $request->getPayload()->getString('_token'))) {
            return true;
        }
        $this->addFlash('error', 'Session expirée, réessayez.');

        return false;
    }
}
