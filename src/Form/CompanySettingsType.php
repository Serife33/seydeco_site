<?php

namespace App\Form;

use App\Service\SettingsProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Plus → Contenus → L'entreprise : texte de l'encadré de l'accueil (story 2.10), clé `Setting`. */
final class CompanySettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(SettingsProvider::COMPANY_TEXT, MarkdownType::class, [
            'label' => 'Présentation',
            'required' => false,
            'empty_data' => '',
            'attr' => ['rows' => 6],
            'constraints' => [new Assert\Length(max: 1500, maxMessage: 'Le texte ne doit pas dépasser {{ limit }} caractères.')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_message' => 'Session expirée, réessayez.']);
    }
}
