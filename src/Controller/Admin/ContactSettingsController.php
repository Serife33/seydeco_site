<?php

namespace App\Controller\Admin;

use App\Entity\Setting;
use App\Form\CompanySettingsType;
use App\Form\ContactSettingsType;
use App\Service\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Coordonnées et réseaux (story 2.1) et texte L'entreprise (story 2.10) : écrits dans `Setting`,
 * relus partout via SettingsProvider (AD-12).
 */
final class ContactSettingsController extends AbstractController
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/coordonnees', name: 'admin_contact_settings', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        return $this->editSettings($request, ContactSettingsType::class, 'Coordonnées et réseaux', 'Coordonnées enregistrées.');
    }

    #[Route('/admin/entreprise', name: 'admin_company_settings', methods: ['GET', 'POST'])]
    public function company(Request $request): Response
    {
        return $this->editSettings($request, CompanySettingsType::class, 'L\'entreprise', 'Texte enregistré.');
    }

    private function editSettings(Request $request, string $type, string $title, string $saved): Response
    {
        $form = $this->createForm($type);
        $keys = array_keys($form->all());
        $form->setData(array_combine($keys, array_map($this->settings->get(...), $keys)))->handleRequest($request);
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

        return $this->render('admin/contact_settings/form.html.twig', ['form' => $form, 'title' => $title]);
    }
}
