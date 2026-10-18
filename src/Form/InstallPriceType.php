<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\PricingType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Une ligne de Réglages → Installation : tarif d'installation par article d'une catégorie (story 3.2). */
final class InstallPriceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $category = $event->getData();
            if (!$category instanceof Category) {
                return;
            }
            $event->getForm()->add('installPrice', AdminMoneyType::class, [
                'label' => $category->getName(),
                'unit' => match ($category->getPricingType()) {
                    PricingType::Chairs => '€ / chaise',
                    PricingType::Tables => '€ / table',
                    default => '€ / article',
                },
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Category::class, 'label' => false]);
    }
}
