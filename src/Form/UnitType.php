<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Nombre entier avec son unité écrite à côté du champ (« % », « jours », « km », « chaises »), UX-DR45. */
final class UnitType extends AbstractType
{
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['unit'] = $options['unit'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('unit')->setAllowedTypes('unit', 'string');
        $resolver->setDefaults([
            'required' => false,
            'invalid_message' => 'Saisir un nombre entier, par exemple 30.',
            // Format français : « 2,5 » est refusé au lieu d'être lu comme 25
            'grouping' => true,
            'attr' => ['inputmode' => 'numeric'],
        ]);
    }

    public function getParent(): string
    {
        return IntegerType::class;
    }
}
