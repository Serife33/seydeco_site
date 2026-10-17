<?php

namespace App\Form;

use App\Service\SettingsProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Plus → Contenus → Coordonnées et réseaux : un champ par clé `Setting` (story 2.1). Tous facultatifs : vide = non affiché. */
final class ContactSettingsType extends AbstractType
{
    /** Domaines acceptés par réseau (sous-domaines compris : www., m., fr.…). */
    private const array NETWORK_HOSTS = [
        'Instagram' => 'instagram\.com',
        'TikTok' => 'tiktok\.com',
        'Facebook' => 'facebook\.com|fb\.com',
        'YouTube' => 'youtube\.com|youtu\.be',
        'Pinterest' => 'pinterest\.(com|fr|co\.uk|de|es|it|ca|com\.au)|pin\.it',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $phone = [new Assert\Regex(pattern: '/^(?=(?:\D*\d){6})\+?[\d\s.()-]{6,20}$/', message: 'Saisir un numéro, par exemple 06 12 34 56 78.')];
        $text = ['required' => false, 'empty_data' => ''];
        $builder
            ->add(SettingsProvider::PHONE, TelType::class, $text + ['label' => 'Téléphone', 'constraints' => $phone, 'help' => 'Vide : le numéro WhatsApp sert aussi pour les appels.'])
            ->add(SettingsProvider::WHATSAPP_PHONE, TelType::class, $text + ['label' => 'Numéro WhatsApp', 'constraints' => $phone])
            ->add(SettingsProvider::ADDRESS, TextareaType::class, $text + ['label' => 'Adresse', 'attr' => ['rows' => 3], 'constraints' => [new Assert\Length(max: 300)]])
            ->add(SettingsProvider::SIRET, TextType::class, $text + [
                'label' => 'SIRET',
                'attr' => ['inputmode' => 'numeric'],
                'constraints' => [new Assert\Regex(pattern: '/^(\d\s*){14}$/', message: 'Le SIRET compte 14 chiffres.')],
            ]);
        foreach (SettingsProvider::SOCIAL_NETWORKS as $network => $key) {
            $builder->add($key, UrlType::class, $text + [
                'label' => "Lien $network",
                'default_protocol' => 'https',
                'constraints' => [
                    new Assert\Length(max: 500),
                    new Assert\Url(message: 'Saisir un lien complet, par exemple https://…', requireTld: true),
                    new Assert\Regex(pattern: '~^https?://([\w-]+\.)*('.self::NETWORK_HOSTS[$network].')(/|$)~i', message: 'Ce lien ne vient pas '.('Instagram' === $network ? "d'" : 'de ').$network.'.'),
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_message' => 'Session expirée, réessayez.']);
    }
}
