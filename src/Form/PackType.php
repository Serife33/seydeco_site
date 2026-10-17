<?php

namespace App\Form;

use App\Entity\Pack;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

final class PackType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom', 'help' => 'Par exemple : Pack 100 invités.'])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false, 'attr' => ['rows' => 4]])
            ->add('photoUpload', FileType::class, [
                'label' => 'Photo (JPEG, PNG ou WebP, 10 Mo maximum)',
                'mapped' => false,
                'required' => false,
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif'],
                'constraints' => [new File(
                    maxSize: '10M',
                    mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                    maxSizeMessage: ProductPhotoType::TOO_BIG,
                    uploadIniSizeErrorMessage: ProductPhotoType::TOO_BIG,
                    uploadFormSizeErrorMessage: ProductPhotoType::TOO_BIG,
                    mimeTypesMessage: 'Format non accepté : choisir une photo JPEG, PNG ou WebP.',
                    uploadErrorMessage: 'La photo n\'a pas pu être envoyée, réessayez.',
                )],
            ])
            ->add('photoAlt', TextType::class, [
                'label' => 'Description de la photo (texte alternatif)',
                'help' => 'Pour les personnes qui n\'ont pas la photo sous les yeux.',
            ])
            ->add('items', CollectionType::class, [
                'label' => 'Produits du pack',
                'entry_type' => PackItemType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'error_bubbling' => false,
            ])
            ->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                $form = $event->getForm();
                if (null === $event->getData()->getPhoto() && null === $form->get('photoUpload')->getData()) {
                    $form->get('photoUpload')->addError(new FormError('Choisir une photo.'));
                }
            });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Pack::class, 'csrf_message' => 'Session expirée, réessayez.']);
    }
}
