<?php

namespace App\Form;

use App\Entity\Review;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ReviewType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('author', TextType::class, ['label' => 'Prénom et initiale', 'help' => 'Par exemple : Inès B.'])
            ->add('rating', ChoiceType::class, [
                'label' => 'Note',
                'choices' => ['5 étoiles' => 5, '4 étoiles' => 4, '3 étoiles' => 3, '2 étoiles' => 2, '1 étoile' => 1],
                'placeholder' => 'Choisir',
                'invalid_message' => 'Choisir la note.',
            ])
            ->add('text', TextareaType::class, ['label' => 'Texte de l\'avis', 'attr' => ['rows' => 6], 'help' => 'Recopié tel quel depuis la fiche Google.'])
            ->add('reviewedOn', DateType::class, [
                'label' => 'Date de l\'avis',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'invalid_message' => 'Saisir une date valide.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Review::class, 'csrf_message' => 'Session expirée, réessayez.']);
    }
}
