<?php

namespace App\Form;

use App\Entity\ProductColor;
use App\Entity\ProductPhoto;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Range;

/** Une ligne photo : fichier (nouvelle photo seulement), texte alternatif, coloris, ordre. */
final class ProductPhotoType extends AbstractType
{
    public const string TOO_BIG = 'Photo trop lourde (10 Mo maximum).';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => 'Photo (JPEG, PNG ou WebP, 10 Mo maximum)',
                'mapped' => false,
                'required' => false,
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
                'constraints' => [new File(
                    maxSize: '10M',
                    mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                    maxSizeMessage: self::TOO_BIG,
                    uploadIniSizeErrorMessage: self::TOO_BIG,
                    uploadFormSizeErrorMessage: self::TOO_BIG,
                    mimeTypesMessage: 'Format non accepté : choisir une photo JPEG, PNG ou WebP.',
                    uploadErrorMessage: 'La photo n\'a pas pu être envoyée, réessayez.',
                )],
            ])
            ->add('alt', TextType::class, [
                'label' => 'Description de la photo (texte alternatif)',
                'help' => 'Pour les personnes qui n\'ont pas la photo sous les yeux.',
            ])
            ->add('color', EntityType::class, [
                'label' => 'Coloris montré',
                'class' => ProductColor::class,
                'choices' => $options['colors'],
                'choice_label' => 'name',
                'placeholder' => 'Aucun en particulier',
                'required' => false,
            ])
            // Renuméroté par le contrôleur Stimulus « collection » (Monter / Descendre) ; nouvelle photo : en dernier
            ->add('position', IntegerType::class, [
                'label' => 'Ordre',
                'empty_data' => '1000',
                'attr' => ['hidden' => true, 'data-collection-position' => ''],
                'constraints' => [new Range(min: 0, max: 10000, notInRangeMessage: 'Ordre de photo invalide, rechargez la page.')],
            ])
            ->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                $form = $event->getForm();
                if (null === $event->getData()?->getFilename() && null === $form->get('file')->getData()) {
                    $form->get('file')->addError(new FormError('Choisir une photo.'));
                }
            });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProductPhoto::class, 'colors' => []]);
    }
}
