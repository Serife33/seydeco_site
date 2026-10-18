<?php

namespace App\Controller\Admin;

use App\Entity\Realisation;
use App\Form\RealisationType;
use App\Repository\RealisationRepository;
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

/** Plus → Contenus → Réalisations (story 2.5). Pas de suppression : une réalisation se masque. */
#[Route('/admin/realisations')]
final class RealisationController extends AbstractController
{
    private const int PER_PAGE = 20;

    public function __construct(
        private readonly RealisationRepository $realisations,
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $media,
    ) {
    }

    #[Route('', name: 'admin_realisation_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = min(max(1, $request->query->getInt('page', 1)), 10_000);
        $realisations = $this->realisations->paginate($page, self::PER_PAGE);
        $pages = max(1, (int) ceil(\count($realisations) / self::PER_PAGE));
        if ($page > $pages) {
            $realisations = $this->realisations->paginate($page = $pages, self::PER_PAGE);
        }

        return $this->render('admin/realisation/index.html.twig', [
            'realisations' => $realisations,
            'page' => $page,
            'pages' => $pages,
            'offset' => ($page - 1) * self::PER_PAGE,
        ]);
    }

    #[Route('/ajouter', name: 'admin_realisation_new', methods: ['GET', 'POST'])]
    public function new(Request $request, SlugGenerator $slugs): Response
    {
        $realisation = new Realisation();
        $form = $this->createForm(RealisationType::class, $realisation)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $realisation->initSlug($slugs->generate($realisation->getTitle(), $this->realisations));
            $realisation->setPosition($this->realisations->nextPosition());
            $this->em->persist($realisation);

            return $this->save($form, $realisation);
        }

        return $this->render('admin/realisation/form.html.twig', ['form' => $form, 'realisation' => $realisation]);
    }

    #[Route('/{id}/modifier', name: 'admin_realisation_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Realisation $realisation, Request $request): Response
    {
        $form = $this->createForm(RealisationType::class, $realisation)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            return $this->save($form, $realisation);
        }

        return $this->render('admin/realisation/form.html.twig', ['form' => $form, 'realisation' => $realisation]);
    }

    #[Route('/{id}/monter', name: 'admin_realisation_move_up', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveUp(Realisation $realisation, Request $request): Response
    {
        return $this->move($realisation, $request, before: true);
    }

    #[Route('/{id}/descendre', name: 'admin_realisation_move_down', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveDown(Realisation $realisation, Request $request): Response
    {
        return $this->move($realisation, $request, before: false);
    }

    #[Route('/{id}/visibilite', name: 'admin_realisation_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleVisibility(Realisation $realisation, Request $request): Response
    {
        if ($this->tokenIsValid($realisation, $request)) {
            $realisation->setVisible(!$realisation->isVisible());
            $this->em->flush();
        }

        return $this->backToList($request);
    }

    private function save(FormInterface $form, Realisation $realisation): Response
    {
        try {
            $this->media->storeForm($form, $realisation);
            // Les anciens fichiers ne sont effacés qu'après ce flush réussi (MediaStorage::postFlush)
            $this->em->flush();
        } catch (FileException) {
            $this->media->abort();
            $form->addError(new FormError('Impossible d\'enregistrer le fichier, réessayez.'));

            return $this->render('admin/realisation/form.html.twig', ['form' => $form, 'realisation' => $realisation], new Response(status: 422));
        } catch (\Throwable $e) {
            $this->media->abort();
            throw $e;
        }
        $this->addFlash('success', 'Réalisation enregistrée.');

        return $this->redirectToRoute('admin_realisation_index');
    }

    private function move(Realisation $realisation, Request $request, bool $before): Response
    {
        if ($this->tokenIsValid($realisation, $request) && $neighbor = $this->realisations->findNeighbor($realisation, $before)) {
            $position = $realisation->getPosition();
            $realisation->setPosition($neighbor->getPosition());
            $neighbor->setPosition($position);
            $this->em->flush();
        }

        return $this->backToList($request);
    }

    private function tokenIsValid(Realisation $realisation, Request $request): bool
    {
        if ($this->isCsrfTokenValid('realisation'.$realisation->getId(), $request->getPayload()->getString('_token'))) {
            return true;
        }
        $this->addFlash('error', 'Session expirée, réessayez.');

        return false;
    }

    private function backToList(Request $request): Response
    {
        return $this->redirectToRoute('admin_realisation_index', ['page' => max(1, $request->getPayload()->getInt('page', 1))]);
    }
}
