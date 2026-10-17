<?php

namespace App\Controller\Admin;

use App\Entity\Setting;
use App\Form\ContactSettingsType;
use App\Service\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Coordonnées et réseaux (story 2.1) : écrites dans `Setting`, relues partout via SettingsProvider (AD-12). */
final class ContactSettingsController extends AbstractController
{
    #[Route('/admin/coordonnees', name: 'admin_contact_settings', methods: ['GET', 'POST'])]
    public function edit(Request $request, SettingsProvider $settings, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ContactSettingsType::class);
        $keys = array_keys($form->all());
        $form->setData(array_combine($keys, array_map($settings->get(...), $keys)))->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($form->getData() as $name => $value) {
                $value = trim((string) $value);
                $setting = $em->find(Setting::class, $name);
                $setting ? $setting->setValue($value) : $em->persist(new Setting($name, $value));
            }
            $em->flush();
            $this->addFlash('success', 'Coordonnées enregistrées.');

            return $this->redirectToRoute('admin_contact_settings');
        }

        return $this->render('admin/contact_settings/form.html.twig', ['form' => $form]);
    }
}
