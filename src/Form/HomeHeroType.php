<?php

namespace App\Form;

use App\Entity\HomeHero;
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

/** Haut de l'accueil (story 2.9) : une ou deux photos (texte alternatif obligatoire), titre, texte, bouton vers une page du site. */
final class HomeHeroType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $photo = fn (string $label) => [
            'label' => $label,
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
        ];
        $alt = ['label' => 'Description de la photo (texte alternatif)', 'required' => false, 'help' => 'Pour les personnes qui ne voient pas la photo.'];
        $builder
            ->add('photoUpload', FileType::class, $photo('Photo (JPEG, PNG ou WebP, 10 Mo maximum)'))
            ->add('photoAlt', TextType::class, $alt)
            ->add('photo2Upload', FileType::class, $photo('Deuxième photo, facultative (côte à côte avec la première)'))
            ->add('photo2Alt', TextType::class, $alt)
            ->add('title', TextType::class, ['label' => 'Titre', 'attr' => ['maxlength' => 60], 'help' => '60 caractères maximum.'])
            ->add('text', TextareaType::class, ['label' => 'Texte', 'attr' => ['rows' => 2, 'maxlength' => 160], 'help' => '160 caractères maximum (2 lignes).'])
            ->add('buttonLabel', TextType::class, ['label' => 'Texte du bouton', 'attr' => ['maxlength' => 40]])
            ->add('buttonLink', TextType::class, ['label' => 'Lien du bouton', 'help' => 'Une page du site, par exemple /location (catalogue) ou /realisations.'])
            ->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                $form = $event->getForm();
                $hero = $event->getData();
                if (null === $hero->getPhoto() && null === $form->get('photoUpload')->getData()) {
                    $form->get('photoUpload')->addError(new FormError('Choisir une photo.'));
                }
                $keepsPhoto2 = $hero->getPhoto2() && !($form->has('removePhoto2') && $form->get('removePhoto2')->getData());
                foreach (['photo' => true, 'photo2' => $keepsPhoto2 || $form->get('photo2Upload')->getData()] as $field => $used) {
                    if ($used && '' === trim((string) $form->get($field.'Alt')->getData())) {
                        $form->get($field.'Alt')->addError(new FormError('Décrivez la photo (ex. : table de mariage dressée sous les lampions).'));
                    }
                }
            });
        if ($options['data']?->getPhoto2()) {
            $builder->add('removePhoto2', CheckboxType::class, ['label' => 'Retirer la deuxième photo', 'mapped' => false, 'required' => false]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => HomeHero::class, 'csrf_message' => 'Session expirée, réessayez.']);
    }
}
