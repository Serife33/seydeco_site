<?php

namespace App\Form;

use App\Entity\Announcement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AnnouncementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = ['widget' => 'single_text', 'input' => 'datetime_immutable', 'invalid_message' => 'Saisir une date valide.'];
        $builder
            ->add('text', TextType::class, ['label' => 'Texte', 'attr' => ['maxlength' => 120], 'help' => '120 caractères maximum. Par exemple : Nouveau : les arches fleuries sont arrivées.'])
            ->add('link', UrlType::class, [
                'label' => 'Lien (facultatif)',
                'required' => false,
                'default_protocol' => 'https',
                'help' => 'Une adresse du site (/location/chaises) ou un lien complet (https://…).',
            ])
            ->add('startsOn', DateType::class, $date + ['label' => 'Date de début'])
            ->add('endsOn', DateType::class, $date + ['label' => 'Date de fin', 'help' => 'L\'annonce disparaît d\'elle-même le lendemain.']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Announcement::class, 'csrf_message' => 'Session expirée, réessayez.']);
    }
}
