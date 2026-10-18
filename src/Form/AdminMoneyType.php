<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Montant saisi en euros (« 45,00 »), stocké en centimes entiers. */
final class AdminMoneyType extends AbstractType
{
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        // Unité écrite à la place du seul « € » : « € / km », « € / chaise »
        if (null !== $options['unit']) {
            $view->vars['money_pattern'] = '{{ widget }} '.$options['unit'];
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'currency' => 'EUR',
            'divisor' => 100,
            'input' => 'integer',
            'required' => false,
            'invalid_message' => 'Saisir un montant en euros, par exemple 45,00.',
            'attr' => ['inputmode' => 'decimal'],
            'unit' => null,
        ]);
    }

    public function getParent(): string
    {
        return MoneyType::class;
    }
}
