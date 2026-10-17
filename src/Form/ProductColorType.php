<?php

namespace App\Form;

use App\Entity\ProductColor;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProductColorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom du coloris', 'help' => 'Par exemple : Blanc, Doré.'])
            ->add('hex', ColorType::class, ['label' => 'Couleur de la pastille', 'invalid_message' => 'Choisir une couleur valide, par exemple #FFFFFF.']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProductColor::class]);
    }
}
