<?php

namespace App\Form;

use App\Entity\PackItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Une ligne de pack : produit + quantité proposée. */
final class PackItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('product', ProductChoiceType::class, ['label' => 'Produit'])
            ->add('quantity', IntegerType::class, [
                'label' => 'Quantité',
                'invalid_message' => 'Saisir un nombre entier, par exemple 100.',
                'grouping' => true,
                'attr' => ['inputmode' => 'numeric'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PackItem::class]);
    }
}
