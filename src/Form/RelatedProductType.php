<?php

namespace App\Form;

use App\Entity\RelatedProduct;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Range;

/** Une ligne « Souvent loué avec » : produit associé + ordre (Monter / Descendre). */
final class RelatedProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('related', ProductChoiceType::class, ['label' => 'Produit associé', 'exclude' => $options['exclude']])
            ->add('position', IntegerType::class, [
                'label' => 'Ordre',
                'empty_data' => '1000',
                'attr' => ['hidden' => true, 'data-collection-position' => ''],
                'constraints' => [new Range(min: 0, max: 10000, notInRangeMessage: 'Ordre invalide, rechargez la page.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RelatedProduct::class, 'exclude' => null]);
    }
}
