<?php

namespace App\Controller\Admin;

use App\Entity\Page;
use App\Form\PageType;
use App\Repository\PageRepository;
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

/** Plus → Contenus → Pages (story 2.3). Aucune suppression : une page se masque. */
#[Route('/admin/pages')]
final class PageController extends AbstractController
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $media,
    ) {
    }

    #[Route('', name: 'admin_page_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/page/index.html.twig', ['pages' => $this->pages->findOrdered()]);
    }

    #[Route('/ajouter', name: 'admin_page_new', methods: ['GET', 'POST'])]
    public function new(Request $request, SlugGenerator $slugs): Response
    {
        $page = new Page();
        $form = $this->createForm(PageType::class, $page)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $page->initSlug($slugs->generate($page->getTitle(), $this->pages));
            $page->setPosition($this->pages->nextPosition());
            $this->em->persist($page);

            return $this->save($form, $page);
        }

        return $this->render('admin/page/form.html.twig', ['form' => $form, 'page' => $page]);
    }

    #[Route('/{id}/modifier', name: 'admin_page_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Page $page, Request $request): Response
    {
        $form = $this->createForm(PageType::class, $page)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            return $this->save($form, $page);
        }

        return $this->render('admin/page/form.html.twig', ['form' => $form, 'page' => $page]);
    }

    #[Route('/{id}/monter', name: 'admin_page_move_up', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveUp(Page $page, Request $request): Response
    {
        return $this->move($page, $request, before: true);
    }

    #[Route('/{id}/descendre', name: 'admin_page_move_down', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveDown(Page $page, Request $request): Response
    {
        return $this->move($page, $request, before: false);
    }

    #[Route('/{id}/visibilite', name: 'admin_page_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleVisibility(Page $page, Request $request): Response
    {
        if ($this->tokenIsValid($page, $request)) {
            $page->setVisible(!$page->isVisible());
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_page_index');
    }

    /** Photo rangée hors de public/ ; l'ancienne n'est effacée qu'après un enregistrement réussi. */
    private function save(FormInterface $form, Page $page): Response
    {
        try {
            $file = $form->get('photoUpload')->getData();
            if ($file || ($form->has('removePhoto') && $form->get('removePhoto')->getData())) {
                $this->media->discard($page->getPhoto());
                $page->setPhoto($file ? $this->media->store($file) : null);
            }
            if (null === $page->getPhoto()) {
                $page->setPhotoAlt(null);
            }
            $this->em->flush();
        } catch (FileException) {
            $this->media->abort();
            $form->addError(new FormError('Impossible d\'enregistrer le fichier, réessayez.'));

            return $this->render('admin/page/form.html.twig', ['form' => $form, 'page' => $page], new Response(status: 422));
        } catch (\Throwable $e) {
            $this->media->abort();
            throw $e;
        }
        $this->addFlash('success', 'Page enregistrée.');

        return $this->redirectToRoute('admin_page_index');
    }

    private function move(Page $page, Request $request, bool $before): Response
    {
        if ($this->tokenIsValid($page, $request) && $neighbor = $this->pages->findNeighbor($page, $before)) {
            $position = $page->getPosition();
            $page->setPosition($neighbor->getPosition());
            $neighbor->setPosition($position);
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_page_index');
    }

    private function tokenIsValid(Page $page, Request $request): bool
    {
        if ($this->isCsrfTokenValid('page'.$page->getId(), $request->getPayload()->getString('_token'))) {
            return true;
        }
        $this->addFlash('error', 'Session expirée, réessayez.');

        return false;
    }
}
