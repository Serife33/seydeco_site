<?php

namespace App\Controller\Admin;

use App\Entity\Pack;
use App\Entity\PackItem;
use App\Form\PackType;
use App\Repository\PackRepository;
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

/** Plus → Contenus → Packs. Pas de suppression : un pack se masque. */
#[Route('/admin/packs')]
final class PackController extends AbstractController
{
    private const int PER_PAGE = 20;

    public function __construct(
        private readonly PackRepository $packs,
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $media,
    ) {
    }

    #[Route('', name: 'admin_pack_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = min(max(1, $request->query->getInt('page', 1)), 10_000);
        $packs = $this->packs->paginate($page, self::PER_PAGE);
        $pages = max(1, (int) ceil(\count($packs) / self::PER_PAGE));
        if ($page > $pages) {
            $packs = $this->packs->paginate($page = $pages, self::PER_PAGE);
        }

        return $this->render('admin/pack/index.html.twig', [
            'packs' => $packs,
            'page' => $page,
            'pages' => $pages,
            'offset' => ($page - 1) * self::PER_PAGE,
        ]);
    }

    #[Route('/ajouter', name: 'admin_pack_new', methods: ['GET', 'POST'])]
    public function new(Request $request, SlugGenerator $slugs): Response
    {
        $pack = new Pack();
        // Une première ligne produit, à compléter
        $pack->addItem(new PackItem());
        $form = $this->createForm(PackType::class, $pack)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $pack->initSlug($slugs->generate($pack->getName(), $this->packs));
            $pack->setPosition($this->packs->nextPosition());
            $this->em->persist($pack);

            return $this->save($form, $pack);
        }

        return $this->render('admin/pack/form.html.twig', ['form' => $form, 'pack' => $pack]);
    }

    #[Route('/{id}/modifier', name: 'admin_pack_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Pack $pack, Request $request): Response
    {
        $form = $this->createForm(PackType::class, $pack)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            return $this->save($form, $pack);
        }

        return $this->render('admin/pack/form.html.twig', ['form' => $form, 'pack' => $pack]);
    }

    #[Route('/{id}/monter', name: 'admin_pack_move_up', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveUp(Pack $pack, Request $request): Response
    {
        return $this->move($pack, $request, before: true);
    }

    #[Route('/{id}/descendre', name: 'admin_pack_move_down', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveDown(Pack $pack, Request $request): Response
    {
        return $this->move($pack, $request, before: false);
    }

    #[Route('/{id}/visibilite', name: 'admin_pack_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleVisibility(Pack $pack, Request $request): Response
    {
        if ($this->tokenIsValid($pack, $request)) {
            $pack->setVisible(!$pack->isVisible());
            $this->em->flush();
        }

        return $this->backToList($request);
    }

    /** Photo rangée hors de public/ ; l'ancienne n'est effacée qu'après un enregistrement réussi. */
    private function save(FormInterface $form, Pack $pack): Response
    {
        try {
            if ($file = $form->get('photoUpload')->getData()) {
                $this->media->discard($pack->getPhoto());
                $pack->setPhoto($this->media->store($file));
            }
            $this->em->flush();
        } catch (FileException) {
            $this->media->abort();
            $form->addError(new FormError('Impossible d\'enregistrer le fichier, réessayez.'));

            return $this->render('admin/pack/form.html.twig', ['form' => $form, 'pack' => $pack], new Response(status: 422));
        } catch (\Throwable $e) {
            $this->media->abort();
            throw $e;
        }
        $this->addFlash('success', 'Pack enregistré.');

        return $this->redirectToRoute('admin_pack_index');
    }

    private function move(Pack $pack, Request $request, bool $before): Response
    {
        if ($this->tokenIsValid($pack, $request) && $neighbor = $this->packs->findNeighbor($pack, $before)) {
            $position = $pack->getPosition();
            $pack->setPosition($neighbor->getPosition());
            $neighbor->setPosition($position);
            $this->em->flush();
        }

        return $this->backToList($request);
    }

    private function tokenIsValid(Pack $pack, Request $request): bool
    {
        if ($this->isCsrfTokenValid('pack'.$pack->getId(), $request->getPayload()->getString('_token'))) {
            return true;
        }
        $this->addFlash('error', 'Session expirée, réessayez.');

        return false;
    }

    private function backToList(Request $request): Response
    {
        return $this->redirectToRoute('admin_pack_index', ['page' => max(1, $request->getPayload()->getInt('page', 1))]);
    }
}
