<?php

namespace App\Controller\Admin;

use App\Entity\Setting;
use App\Form\AdminMoneyType;
use App\Form\CompanySettingsType;
use App\Form\ContactSettingsType;
use App\Form\DeliverySettingsType;
use App\Form\InstallPriceType;
use App\Form\PaymentSettingsType;
use App\Form\QuoteSettingsType;
use App\Form\TermsSettingsType;
use App\Form\UnitType;
use App\Repository\CategoryRepository;
use App\Service\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Valid;

/**
 * Réglages écrits dans `Setting` et relus partout via SettingsProvider (AD-12) : coordonnées (story 2.1),
 * texte L'entreprise (story 2.10), puis Plus → Réglages (stories 3.1 + 3.2).
 */
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/coordonnees', name: 'admin_contact_settings', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        return $this->editSettings($request, ContactSettingsType::class, 'Coordonnées et réseaux', 'Coordonnées enregistrées.', 'Affichés dans l\'en-tête et le pied de page du site. Un champ laissé vide n\'est affiché nulle part.');
    }

    #[Route('/admin/entreprise', name: 'admin_company_settings', methods: ['GET', 'POST'])]
    public function company(Request $request): Response
    {
        return $this->editSettings($request, CompanySettingsType::class, 'L\'entreprise', 'Texte enregistré.', 'Encadré « L\'entreprise » en bas de l\'accueil, avec vos réseaux et vos contacts (Coordonnées et réseaux).');
    }

    #[Route('/admin/reglages', name: 'admin_settings', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/settings/index.html.twig');
    }

    #[Route('/admin/reglages/devis', name: 'admin_quote_settings', methods: ['GET', 'POST'])]
    public function quote(Request $request): Response
    {
        return $this->editSettings($request, QuoteSettingsType::class, 'Devis', 'Réglages enregistrés.', 'Un réglage modifié ne change que les estimations et devis à venir. Un champ vide n\'est pas encore renseigné.', 'admin_settings');
    }

    #[Route('/admin/reglages/livraison', name: 'admin_delivery_settings', methods: ['GET', 'POST'])]
    public function delivery(Request $request): Response
    {
        return $this->editSettings($request, DeliverySettingsType::class, 'Livraison', 'Réglages enregistrés.', 'Un réglage modifié ne change que les estimations et devis à venir. Tarif, minimum, distance et ville sont aussi affichés sur le site.', 'admin_settings');
    }

    #[Route('/admin/reglages/conditions', name: 'admin_terms_settings', methods: ['GET', 'POST'])]
    public function terms(Request $request): Response
    {
        return $this->editSettings($request, TermsSettingsType::class, 'Conditions', 'Conditions enregistrées.', 'Ces textes sont repris dans l\'encadré « Avant de réserver » et sur chaque devis.', 'admin_settings');
    }

    #[Route('/admin/reglages/paiement', name: 'admin_payment_settings', methods: ['GET', 'POST'])]
    public function payment(Request $request): Response
    {
        return $this->editSettings($request, PaymentSettingsType::class, 'Paiement', 'Réglages enregistrés.', 'Vos coordonnées bancaires ne s\'affichent qu\'après votre validation d\'un devis.', 'admin_settings');
    }

    /** Tarif d'installation de chaque catégorie (`Category.installPrice`, story 3.2) ; vide = « Tarif sur demande ». */
    #[Route('/admin/reglages/installation', name: 'admin_install_settings', methods: ['GET', 'POST'])]
    public function install(Request $request, CategoryRepository $categories): Response
    {
        $form = $this->createFormBuilder(['categories' => $categories->findBy([], ['position' => 'ASC', 'id' => 'ASC'])], ['csrf_message' => 'Session expirée, réessayez.'])
            ->add('categories', CollectionType::class, ['entry_type' => InstallPriceType::class, 'label' => false, 'attr' => ['class' => 'admin-collection'], 'constraints' => [new Valid()]])
            ->getForm()
            ->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Tarifs enregistrés.');

            return $this->redirectToRoute('admin_install_settings');
        }

        return $this->render('admin/settings/form.html.twig', [
            'form' => $form,
            'title' => 'Installation',
            'intro' => 'Tarif par article installé, proposé seulement en livraison. Un champ vide : installation « Tarif sur demande », chiffrée par vous à la validation.',
            'back' => 'admin_settings',
        ]);
    }

    private function editSettings(Request $request, string $type, string $title, string $saved, string $intro, string $back = 'admin_more'): Response
    {
        $form = $this->createForm($type);
        $data = [];
        foreach ($form->all() as $key => $field) {
            $value = $this->settings->get($key);
            // Vide = non renseigné ; un nombre illisible en base (saisi à la main) est montré vide plutôt que de bloquer l'écran
            $type = $field->getConfig()->getType()->getInnerType();
            $data[$key] = '' === $value || (($type instanceof UnitType || $type instanceof AdminMoneyType) && !ctype_digit($value)) ? null : $value;
        }
        $form->setData($data)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($form->getData() as $name => $value) {
                $value = trim((string) $value);
                $setting = $this->em->find(Setting::class, $name);
                $setting ? $setting->setValue($value) : $this->em->persist(new Setting($name, $value));
            }
            $this->em->flush();
            $this->addFlash('success', $saved);

            return $this->redirectToRoute($request->attributes->getString('_route'));
        }

        return $this->render('admin/settings/form.html.twig', ['form' => $form, 'title' => $title, 'intro' => $intro, 'back' => $back]);
    }
}
