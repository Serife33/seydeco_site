<?php

namespace App\Form;

use App\Entity\DepotClosure;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DepotClosureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = ['widget' => 'single_text', 'input' => 'datetime_immutable', 'invalid_message' => 'Saisir une date valide.'];
        $builder
            ->add('startsOn', DateType::class, $date + ['label' => 'Date (ou début de la période)'])
            ->add('endsOn', DateType::class, $date + ['label' => 'Fin de la période (facultatif)', 'required' => false, 'help' => 'Laissez vide pour une fermeture d\'un seul jour.'])
            ->add('reason', TextType::class, ['label' => 'Motif (facultatif)', 'required' => false, 'attr' => ['maxlength' => 120], 'help' => 'Pour vous seulement, jamais affiché sur le site. Par exemple : congés.']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DepotClosure::class, 'csrf_message' => 'Session expirée, réessayez.']);
    }
}
