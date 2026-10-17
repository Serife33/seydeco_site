<?php

namespace App\Form;

use App\Entity\PriceTier;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PriceTierType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('minQty', IntegerType::class, [
                'label' => 'À partir de (quantité)',
                'invalid_message' => 'Saisir un nombre entier, par exemple 50.',
                'grouping' => true,
                'attr' => ['inputmode' => 'numeric'],
            ])
            ->add('price', AdminMoneyType::class, ['label' => 'Prix unitaire', 'required' => true]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PriceTier::class]);
    }
}
