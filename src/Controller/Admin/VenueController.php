<?php

namespace App\Controller\Admin;

use App\Entity\Venue;
use App\Form\VenueType;
use App\Repository\VenueRepository;
use App\Service\MediaStorage;
use App\Service\SlugGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Plus → Contenus → Lieux (story 2.6). Pas de suppression : un lieu se masque. */
#[Route('/admin/lieux')]
final class VenueController extends AbstractController
{
    private const int PER_PAGE = 20;

    public function __construct(
        private readonly VenueRepository $venues,
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $media,
    ) {
    }

    #[Route('', name: 'admin_venue_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = min(max(1, $request->query->getInt('page', 1)), 10_000);
        $venues = $this->venues->paginate($page, self::PER_PAGE);
        $pages = max(1, (int) ceil(\count($venues) / self::PER_PAGE));
        if ($page > $pages) {
            $venues = $this->venues->paginate($page = $pages, self::PER_PAGE);
        }

        return $this->render('admin/venue/index.html.twig', [
            'venues' => $venues,
            'page' => $page,
            'pages' => $pages,
            'offset' => ($page - 1) * self::PER_PAGE,
        ]);
    }

    #[Route('/ajouter', name: 'admin_venue_new', methods: ['GET', 'POST'])]
    public function new(Request $request, SlugGenerator $slugs): Response
    {
        $venue = new Venue();
        $form = $this->createForm(VenueType::class, $venue)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $venue->initSlug($slugs->generate($venue->getName(), $this->venues));
            $venue->setPosition($this->venues->nextPosition());
            $this->em->persist($venue);

            return $this->save($form, $venue);
        }

        return $this->render('admin/venue/form.html.twig', ['form' => $form, 'venue' => $venue]);
    }

    #[Route('/{id}/modifier', name: 'admin_venue_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Venue $venue, Request $request): Response
    {
        $form = $this->createForm(VenueType::class, $venue)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            return $this->save($form, $venue);
        }

        return $this->render('admin/venue/form.html.twig', ['form' => $form, 'venue' => $venue]);
    }

    #[Route('/{id}/monter', name: 'admin_venue_move_up', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveUp(Venue $venue, Request $request): Response
    {
        return $this->move($venue, $request, before: true);
    }

    #[Route('/{id}/descendre', name: 'admin_venue_move_down', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveDown(Venue $venue, Request $request): Response
    {
        return $this->move($venue, $request, before: false);
    }

    #[Route('/{id}/visibilite', name: 'admin_venue_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleVisibility(Venue $venue, Request $request): Response
    {
        if ($this->tokenIsValid($venue, $request)) {
            $venue->setVisible(!$venue->isVisible());
            $this->em->flush();
        }

        return $this->backToList($request);
    }

    private function save(FormInterface $form, Venue $venue): Response
    {
        try {
            // Photo rangée hors de public/ ; l'ancienne n'est effacée qu'après un enregistrement réussi
            if ($file = $form->get('photoUpload')->getData()) {
                $this->media->discard($venue->getPhoto());
                $venue->setPhoto($this->media->store($file));
            }
            $this->em->flush();
        } catch (FileException) {
            $this->media->abort();
            $form->addError(new FormError('Impossible d\'enregistrer le fichier, réessayez.'));

            return $this->render('admin/venue/form.html.twig', ['form' => $form, 'venue' => $venue], new Response(status: 422));
        } catch (\Throwable $e) {
            $this->media->abort();
            throw $e;
        }
        $this->addFlash('success', 'Lieu enregistré.');

        return $this->redirectToRoute('admin_venue_index');
    }

    private function move(Venue $venue, Request $request, bool $before): Response
    {
        if ($this->tokenIsValid($venue, $request) && $neighbor = $this->venues->findNeighbor($venue, $before)) {
            $position = $venue->getPosition();
            $venue->setPosition($neighbor->getPosition());
            $neighbor->setPosition($position);
            $this->em->flush();
        }

        return $this->backToList($request);
    }

    private function tokenIsValid(Venue $venue, Request $request): bool
    {
        if ($this->isCsrfTokenValid('venue'.$venue->getId(), $request->getPayload()->getString('_token'))) {
            return true;
        }
        $this->addFlash('error', 'Session expirée, réessayez.');

        return false;
    }

    private function backToList(Request $request): Response
    {
        return $this->redirectToRoute('admin_venue_index', ['page' => max(1, $request->getPayload()->getInt('page', 1))]);
    }
}
