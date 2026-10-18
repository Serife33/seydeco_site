<?php

namespace App\Controller\Admin;

use App\Entity\HomeHero;
use App\Form\HomeHeroType;
use App\Repository\HomeHeroRepository;
use App\Service\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Plus → Contenus → Haut de l'accueil (story 2.9) : on modifie un brouillon, on le regarde (Aperçu, HomeController),
 * puis « Publier » ou « Annuler le brouillon ». Le site public garde la version publiée jusque-là.
 * Brouillon et version publiée peuvent partager un fichier photo : il n'est effacé que s'il ne sert plus à aucun des deux.
 */
#[Route('/admin/haut-accueil')]
final class HomeHeroController extends AbstractController
{
    public function __construct(
        private readonly HomeHeroRepository $heroes,
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $media,
    ) {
    }

    #[Route('', name: 'admin_home_hero', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $published = $this->heroes->published();
        $draft = $this->heroes->findDraft() ?? HomeHero::draftOf($published);
        $form = $this->createForm(HomeHeroType::class, $draft)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            return $this->saveDraft($form, $draft, $published);
        }

        return $this->render('admin/home_hero/form.html.twig', ['form' => $form, 'draft' => $draft, 'published' => $published]);
    }

    #[Route('/publier', name: 'admin_home_hero_publish', methods: ['POST'])]
    public function publish(Request $request): Response
    {
        if ($this->tokenIsValid($request) && $draft = $this->heroes->findDraft()) {
            $published = $this->heroes->published();
            array_map($this->media->discard(...), array_diff($published->getFiles(), $draft->getFiles()));
            $published->copyFrom($draft);
            $this->em->persist($published);
            $this->em->remove($draft);
            $this->em->flush();
            $this->addFlash('success', 'Haut de l\'accueil publié.');
        }

        return $this->redirectToRoute('admin_home_hero');
    }

    #[Route('/annuler', name: 'admin_home_hero_cancel', methods: ['POST'])]
    public function cancel(Request $request): Response
    {
        if ($this->tokenIsValid($request) && $draft = $this->heroes->findDraft()) {
            array_map($this->media->discard(...), array_diff($draft->getFiles(), $this->heroes->published()->getFiles()));
            $this->em->remove($draft);
            $this->em->flush();
            $this->addFlash('success', 'Brouillon annulé : le site garde la version publiée.');
        }

        return $this->redirectToRoute('admin_home_hero');
    }

    private function saveDraft(FormInterface $form, HomeHero $draft, HomeHero $published): Response
    {
        // Une photo remplacée n'est effacée que si la version publiée ne s'en sert pas
        $discard = fn (?string $file) => \in_array($file, $published->getFiles(), true) ? null : $this->media->discard($file);
        try {
            if ($file = $form->get('photoUpload')->getData()) {
                $discard($draft->getPhoto());
                $draft->setPhoto($this->media->store($file));
            }
            $file = $form->get('photo2Upload')->getData();
            if ($file || ($form->has('removePhoto2') && $form->get('removePhoto2')->getData())) {
                $discard($draft->getPhoto2());
                $draft->setPhoto2($file ? $this->media->store($file) : null);
            }
            if (null === $draft->getPhoto2()) {
                $draft->setPhoto2Alt(null);
            }
            $this->em->persist($draft);
            $this->em->flush();
        } catch (FileException) {
            $this->media->abort();
            $form->addError(new FormError('Impossible d\'enregistrer le fichier, réessayez.'));

            return $this->render('admin/home_hero/form.html.twig', ['form' => $form, 'draft' => $draft, 'published' => $published], new Response(status: 422));
        } catch (\Throwable $e) {
            $this->media->abort();
            throw $e;
        }
        $this->addFlash('success', 'Brouillon enregistré. Regardez l\'aperçu, puis publiez.');

        return $this->redirectToRoute('admin_home_hero');
    }

    private function tokenIsValid(Request $request): bool
    {
        if ($this->isCsrfTokenValid('home_hero', $request->getPayload()->getString('_token'))) {
            return true;
        }
        $this->addFlash('error', 'Session expirée, réessayez.');

        return false;
    }
}
