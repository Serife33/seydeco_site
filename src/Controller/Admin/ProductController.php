<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Form\ProductType;
use App\Repository\CategoryRepository;
use App\Repository\EventTypeRepository;
use App\Repository\ProductRepository;
use App\Repository\StyleRepository;
use App\Service\SlugGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Pas de suppression : un produit se masque. */
#[Route('/admin/produits')]
final class ProductController extends AbstractController
{
    private const int PER_PAGE = 20;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_product_index', methods: ['GET'])]
    public function index(Request $request, CategoryRepository $categories): Response
    {
        $page = min(max(1, $request->query->getInt('page', 1)), 10_000);
        $query = trim($request->query->getString('q'));
        $category = $categories->find($request->query->getInt('categorie'));
        $products = $this->products->search($query, $category, $page, self::PER_PAGE);
        $pages = max(1, (int) ceil(\count($products) / self::PER_PAGE));
        if ($page > $pages) {
            $products = $this->products->search($query, $category, $page = $pages, self::PER_PAGE);
        }

        return $this->render('admin/product/index.html.twig', [
            'products' => $products,
            'page' => $page,
            'pages' => $pages,
            'query' => $query,
            'category' => $category,
            'categories' => $categories->findBy([], ['position' => 'ASC']),
        ]);
    }

    #[Route('/ajouter', name: 'admin_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request, SlugGenerator $slugs, StyleRepository $styles, EventTypeRepository $eventTypes): Response
    {
        $product = new Product();
        $form = $this->createForm(ProductType::class, $product)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $product->initSlug($slugs->generate($product->getName(), $this->products));
            $this->em->persist($product);

            return $this->save($form, $product, $styles, $eventTypes, 'new');
        }

        return $this->render('admin/product/form.html.twig', ['form' => $form, 'product' => $product, 'draft_key' => 'new']);
    }

    #[Route('/{id}/modifier', name: 'admin_product_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Product $product, Request $request, StyleRepository $styles, EventTypeRepository $eventTypes): Response
    {
        $form = $this->createForm(ProductType::class, $product)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            return $this->save($form, $product, $styles, $eventTypes, (string) $product->getId());
        }

        return $this->render('admin/product/form.html.twig', ['form' => $form, 'product' => $product, 'draft_key' => $product->getId()]);
    }

    #[Route('/{id}/visibilite', name: 'admin_product_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleVisibility(Product $product, Request $request): Response
    {
        if ($this->isCsrfTokenValid('product'.$product->getId(), $request->getPayload()->getString('_token'))) {
            $product->setVisible(!$product->isVisible());
            $this->em->flush();
        } else {
            $this->addFlash('error', 'Session expirée, réessayez.');
        }

        return 'edit' === $request->getPayload()->getString('from')
            ? $this->redirectToRoute('admin_product_edit', ['id' => $product->getId()])
            // Retour à la liste avec la même recherche, le même filtre et la même page
            : $this->redirectToRoute('admin_product_index', array_intersect_key($request->query->all(), ['q' => 1, 'categorie' => 1, 'page' => 1]));
    }

    /** Les champs « Ajouter un style / type » créent l'élément, ou réutilisent l'existant. */
    private function save(FormInterface $form, Product $product, StyleRepository $styles, EventTypeRepository $eventTypes, string $draftKey): Response
    {
        if ('' !== $name = trim((string) $form->get('newStyle')->getData())) {
            $product->addStyle($styles->findOrCreate($name));
        }
        if ('' !== $name = trim((string) $form->get('newEventType')->getData())) {
            $product->addEventType($eventTypes->findOrCreate($name));
        }
        $this->em->flush();
        $this->addFlash('success', 'Produit enregistré.');
        // Le navigateur efface alors le brouillon local de ce formulaire
        $this->addFlash('draft_saved', $draftKey);

        return $this->redirectToRoute('admin_product_index');
    }
}
