<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\PricingType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom'])
            ->add('pricingType', EnumType::class, [
                'label' => 'Type tarifaire',
                'class' => PricingType::class,
                'choice_label' => fn (PricingType $type) => $type->label(),
                'placeholder' => 'Choisir…',
            ])
            ->add('refurbishDelay', IntegerType::class, [
                'label' => 'Délai de remise en état (heures)',
                'invalid_message' => 'Saisir un nombre entier d\'heures, par exemple 2.',
                // Format français : « 2,5 » est refusé au lieu d'être lu comme 25
                'grouping' => true,
                'attr' => ['inputmode' => 'numeric', 'min' => 0],
            ])
            ->add('installPrice', AdminMoneyType::class, [
                'label' => 'Tarif d\'installation (facultatif)',
                'help' => 'Vide : « tarif sur demande ».',
            ])
            ->add('breakagePrice', AdminMoneyType::class, ['label' => 'Prix de casse par défaut'])
            ->add('lossPrice', AdminMoneyType::class, ['label' => 'Prix de perte par défaut'])
            ->add('returnInstructions', TextareaType::class, [
                'label' => 'Consignes de retour par défaut',
                'required' => false,
                'attr' => ['rows' => 4],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Category::class, 'csrf_message' => 'Session expirée, réessayez.']);
    }
}
