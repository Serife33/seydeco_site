<?php

namespace App\Controller;

use App\Repository\ProductRepository;
use App\Service\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Page « Mon devis » minimale (story 1.13) : la sélection vit dans le navigateur, le serveur ne reçoit que des identifiants. */
final class QuoteController extends AbstractController
{
    private const int MAX_LINES = 100;
    private const int MAX_QTY = 100_000;

    public function __construct(private readonly ProductRepository $products, private readonly SettingsProvider $settings)
    {
    }

    /** Coquille cacheable, sans sélection ; le contrôleur `selection` y charge les lignes. */
    #[Route('/mon-devis', name: 'quote', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('quote/index.html.twig', [
            'quoteOnline' => $this->settings->quoteOnline(),
            'whatsapp' => $this->settings->whatsappNumber(),
        ]);
    }

    /**
     * Finaliser ma demande (stories 3.9, 3.10) : coquille sans sélection ni montant, une étape visible à la fois.
     * Saisies gardées dans le navigateur par le contrôleur `finalize` ; prix, créneaux et période viennent de POST /estimation.
     */
    #[Route('/mon-devis/finaliser', name: 'quote_finalize', methods: ['GET'])]
    public function finalize(): Response
    {
        $fee = $this->settings->get(SettingsProvider::CLEANING_FEE);

        return $this->render('quote/finalize.html.twig', [
            'quoteOnline' => $this->settings->quoteOnline(),
            'whatsapp' => $this->settings->whatsappNumber(),
            'depotCity' => trim($this->settings->get(SettingsProvider::DEPOT_CITY)),
            'pickupTerms' => trim($this->settings->get(SettingsProvider::PICKUP_TERMS)),
            'fuelTerms' => trim($this->settings->get(SettingsProvider::FUEL_TERMS)),
            // Supplément vide : option « sans nettoyage » non proposée
            'cleaningFee' => ctype_digit($fee) ? (int) $fee : null,
        ]);
    }

    /** Lignes `l[]=produit:coloris:quantité` (coloris facultatif) → produits retrouvés au serveur. */
    #[Route('/mon-devis/lignes', name: 'quote_lines', methods: ['GET'])]
    public function lines(Request $request): Response
    {
        if (!$this->settings->quoteOnline()) {
            throw $this->createNotFoundException();
        }
        $lines = [];
        foreach (\array_slice(array_filter((array) ($request->query->all()['l'] ?? []), 'is_string'), 0, self::MAX_LINES) as $raw) {
            // Ligne illisible (lien modifié à la main) : ignorée
            if (preg_match('/^([1-9]\d{0,9}):([1-9]\d{0,9})?:([1-9]\d{0,5})$/', $raw, $m)) {
                $lines[] = ['productId' => (int) $m[1], 'colorId' => '' !== $m[2] ? (int) $m[2] : null, 'qty' => min((int) $m[3], self::MAX_QTY)];
            }
        }
        $products = [];
        foreach ($lines ? $this->products->findBy(['id' => array_unique(array_column($lines, 'productId'))]) : [] as $product) {
            $products[$product->getId()] = $product;
        }
        $rows = [];
        foreach ($lines as $line) {
            $product = $products[$line['productId']] ?? null;
            $color = null;
            foreach ($product?->getColors() ?? [] as $candidate) {
                if ($candidate->getId() === $line['colorId']) {
                    $color = $candidate;
                }
            }
            $rows[] = $line + [
                'product' => $product,
                'color' => $color,
                // Masqué depuis l'ajout (ou identifiant inconnu) : « Ce produit n'est plus proposé » + « Retirer »
                'available' => $product && $product->isVisible() && $product->getCategory()->isVisible(),
            ];
        }

        // Souvent loué avec (story 3.7) : associés des produits proposés, hors produits déjà choisis, 4 au plus
        $available = array_values(array_unique(array_map(fn (array $row) => $row['productId'], array_filter($rows, fn (array $row) => $row['available']))));
        $related = [];
        foreach ($this->products->findPublicRelated($available) as $list) {
            foreach ($list as $product) {
                if (!\in_array($product->getId(), $available, true)) {
                    $related[$product->getId()] = $product;
                }
            }
        }

        return $this->render('quote/_lines.html.twig', [
            'rows' => $rows,
            'related' => \array_slice(array_values($related), 0, 4),
            // Encadré Conditions : casse et perte de chaque produit proposé, une fois
            'products' => array_map(fn (int $id) => $products[$id], $available),
        ]);
    }
}
