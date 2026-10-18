<?php

namespace App\Form;

use App\Entity\Slot;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SlotType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $time = ['widget' => 'single_text', 'input' => 'datetime_immutable', 'invalid_message' => 'Saisir une heure valide.'];
        $builder
            ->add('weekday', ChoiceType::class, ['label' => 'Jour', 'choices' => array_flip(Slot::WEEKDAYS)])
            ->add('startsAt', TimeType::class, $time + ['label' => 'Début'])
            ->add('endsAt', TimeType::class, $time + ['label' => 'Fin']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Slot::class, 'csrf_message' => 'Session expirée, réessayez.']);
    }
}
