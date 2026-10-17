<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Form\CategoryType;
use App\Repository\CategoryRepository;
use App\Service\SlugGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Pas de suppression : une catégorie se masque. */
#[Route('/admin/categories')]
final class CategoryController extends AbstractController
{
    private const int PER_PAGE = 20;

    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_category_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = min(max(1, $request->query->getInt('page', 1)), 10_000);
        $categories = $this->categories->paginate($page, self::PER_PAGE);
        $pages = max(1, (int) ceil(\count($categories) / self::PER_PAGE));
        if ($page > $pages) {
            $categories = $this->categories->paginate($page = $pages, self::PER_PAGE);
        }

        return $this->render('admin/category/index.html.twig', [
            'categories' => $categories,
            'page' => $page,
            'pages' => $pages,
            'offset' => ($page - 1) * self::PER_PAGE,
        ]);
    }

    #[Route('/ajouter', name: 'admin_category_new', methods: ['GET', 'POST'])]
    public function new(Request $request, SlugGenerator $slugs): Response
    {
        $category = new Category();
        $form = $this->createForm(CategoryType::class, $category)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $category->initSlug($slugs->generate($category->getName(), $this->categories));
            $category->setPosition($this->categories->nextPosition());
            $this->em->persist($category);
            $this->em->flush();
            $this->addFlash('success', 'Catégorie enregistrée.');

            return $this->redirectToRoute('admin_category_index');
        }

        return $this->render('admin/category/form.html.twig', ['form' => $form, 'category' => $category]);
    }

    #[Route('/{id}/modifier', name: 'admin_category_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Category $category, Request $request): Response
    {
        $form = $this->createForm(CategoryType::class, $category)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Catégorie enregistrée.');

            return $this->redirectToRoute('admin_category_index');
        }

        return $this->render('admin/category/form.html.twig', ['form' => $form, 'category' => $category]);
    }

    #[Route('/{id}/monter', name: 'admin_category_move_up', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveUp(Category $category, Request $request): Response
    {
        return $this->move($category, $request, before: true);
    }

    #[Route('/{id}/descendre', name: 'admin_category_move_down', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveDown(Category $category, Request $request): Response
    {
        return $this->move($category, $request, before: false);
    }

    #[Route('/{id}/visibilite', name: 'admin_category_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleVisibility(Category $category, Request $request): Response
    {
        if ($this->tokenIsValid($category, $request)) {
            $category->setVisible(!$category->isVisible());
            $this->em->flush();
        }

        return $this->backToList($request);
    }

    private function move(Category $category, Request $request, bool $before): Response
    {
        if ($this->tokenIsValid($category, $request) && $neighbor = $this->categories->findNeighbor($category, $before)) {
            $position = $category->getPosition();
            $category->setPosition($neighbor->getPosition());
            $neighbor->setPosition($position);
            $this->em->flush();
        }

        return $this->backToList($request);
    }

    private function tokenIsValid(Category $category, Request $request): bool
    {
        if ($this->isCsrfTokenValid('category'.$category->getId(), $request->getPayload()->getString('_token'))) {
            return true;
        }
        $this->addFlash('error', 'Session expirée, réessayez.');

        return false;
    }

    private function backToList(Request $request): Response
    {
        return $this->redirectToRoute('admin_category_index', ['page' => max(1, $request->getPayload()->getInt('page', 1))]);
    }
}
