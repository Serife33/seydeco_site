<?php

namespace App\Form;

use App\Entity\Page;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

final class PageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Titre'])
            ->add('content', MarkdownType::class, ['label' => 'Contenu'])
            ->add('description', TextareaType::class, [
                'label' => 'Description pour Google',
                'required' => false,
                'attr' => ['rows' => 2, 'maxlength' => 160],
                'help' => '160 caractères maximum. Vide : le début du contenu est utilisé.',
            ])
            ->add('photoUpload', FileType::class, [
                'label' => 'Photo facultative (JPEG, PNG ou WebP, 10 Mo maximum)',
                'mapped' => false,
                'required' => false,
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
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
                'required' => false,
                'help' => 'Obligatoire avec une photo : pour les personnes qui ne la voient pas.',
            ])
            ->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                $form = $event->getForm();
                // Photo envoyée, ou gardée : texte alternatif obligatoire
                $keepsPhoto = $event->getData()->getPhoto() && !($form->has('removePhoto') && $form->get('removePhoto')->getData());
                if (($form->get('photoUpload')->getData() || $keepsPhoto) && '' === trim((string) $event->getData()->getPhotoAlt())) {
                    $form->get('photoAlt')->addError(new FormError('Décrivez la photo (ex. : Serife installe une table de mariage).'));
                }
            });
        if ($options['data']?->getPhoto()) {
            $builder->add('removePhoto', CheckboxType::class, ['label' => 'Retirer la photo', 'mapped' => false, 'required' => false]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Page::class, 'csrf_message' => 'Session expirée, réessayez.']);
    }
}
