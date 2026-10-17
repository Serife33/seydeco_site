<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Montant saisi en euros (« 45,00 »), stocké en centimes entiers. */
final class AdminMoneyType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'currency' => 'EUR',
            'divisor' => 100,
            'input' => 'integer',
            'required' => false,
            'invalid_message' => 'Saisir un montant en euros, par exemple 45,00.',
            'attr' => ['inputmode' => 'decimal'],
        ]);
    }

    public function getParent(): string
    {
        return MoneyType::class;
    }
}
