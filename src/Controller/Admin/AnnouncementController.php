<?php

namespace App\Controller\Admin;

use App\Entity\Announcement;
use App\Form\AnnouncementType;
use App\Repository\AnnouncementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Plus → Contenus → Annonces (story 2.7). Aucune suppression : une annonce se masque. */
#[Route('/admin/annonces')]
final class AnnouncementController extends AbstractController
{
    public function __construct(
        private readonly AnnouncementRepository $announcements,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_announcement_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/announcement/index.html.twig', [
            'announcements' => $this->announcements->findOrdered(),
            'today' => new \DateTimeImmutable('today'),
        ]);
    }

    #[Route('/ajouter', name: 'admin_announcement_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $announcement = new Announcement();
        $form = $this->createForm(AnnouncementType::class, $announcement)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $announcement->setPosition($this->announcements->nextPosition());
            $this->em->persist($announcement);

            return $this->saved();
        }

        return $this->render('admin/announcement/form.html.twig', ['form' => $form, 'announcement' => $announcement]);
    }

    #[Route('/{id}/modifier', name: 'admin_announcement_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Announcement $announcement, Request $request): Response
    {
        $form = $this->createForm(AnnouncementType::class, $announcement)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            return $this->saved();
        }

        return $this->render('admin/announcement/form.html.twig', ['form' => $form, 'announcement' => $announcement]);
    }

    #[Route('/{id}/monter', name: 'admin_announcement_move_up', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveUp(Announcement $announcement, Request $request): Response
    {
        return $this->move($announcement, $request, before: true);
    }

    #[Route('/{id}/descendre', name: 'admin_announcement_move_down', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveDown(Announcement $announcement, Request $request): Response
    {
        return $this->move($announcement, $request, before: false);
    }

    #[Route('/{id}/visibilite', name: 'admin_announcement_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleVisibility(Announcement $announcement, Request $request): Response
    {
        if ($this->tokenIsValid($announcement, $request)) {
            $announcement->setVisible(!$announcement->isVisible());
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_announcement_index');
    }

    private function saved(): Response
    {
        $this->em->flush();
        $this->addFlash('success', 'Annonce enregistrée.');

        return $this->redirectToRoute('admin_announcement_index');
    }

    private function move(Announcement $announcement, Request $request, bool $before): Response
    {
        if ($this->tokenIsValid($announcement, $request) && $neighbor = $this->announcements->findNeighbor($announcement, $before)) {
            $position = $announcement->getPosition();
            $announcement->setPosition($neighbor->getPosition());
            $neighbor->setPosition($position);
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_announcement_index');
    }

    private function tokenIsValid(Announcement $announcement, Request $request): bool
    {
        if ($this->isCsrfTokenValid('announcement'.$announcement->getId(), $request->getPayload()->getString('_token'))) {
            return true;
        }
        $this->addFlash('error', 'Session expirée, réessayez.');

        return false;
    }
}
