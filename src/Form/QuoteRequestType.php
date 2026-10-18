<?php

namespace App\Form;

use App\Entity\QuoteRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

/**
 * Coordonnées et consentements de Finaliser (story 3.11), envoyés avec la demande (story 3.12).
 * `data-missing` / `data-invalid` : messages du serveur repris par le navigateur à « Continuer ».
 * `draft` : sélection, date, mode et créneaux gardés dans le navigateur, tout recalculé au serveur.
 */
final class QuoteRequestType extends AbstractType
{
    public const string TERMS_MISSING = 'Cochez cette case pour accepter les conditions de location.';
    public const string PRIVACY_MISSING = 'Cochez cette case pour accepter l\'utilisation de vos coordonnées.';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, ['label' => 'Prénom', 'attr' => ['autocomplete' => 'given-name', 'maxlength' => 80, 'data-missing' => 'Indiquez votre prénom.']])
            ->add('lastName', TextType::class, ['label' => 'Nom', 'attr' => ['autocomplete' => 'family-name', 'maxlength' => 80, 'data-missing' => 'Indiquez votre nom.']])
            ->add('phone', TelType::class, ['label' => 'Téléphone', 'attr' => [
                'autocomplete' => 'tel', 'maxlength' => 30, 'pattern' => '\+?(?:[\s.\-]?\d){9,14}',
                'data-missing' => 'Indiquez votre téléphone.', 'data-invalid' => QuoteRequest::PHONE_INVALID,
            ]])
            ->add('email', EmailType::class, ['label' => 'Email', 'attr' => [
                'autocomplete' => 'email', 'maxlength' => 180, 'data-missing' => QuoteRequest::EMAIL_MISSING, 'data-invalid' => QuoteRequest::EMAIL_INVALID,
            ]])
            ->add('companyName', TextType::class, ['label' => 'Raison sociale', 'required' => false, 'attr' => ['autocomplete' => 'organization', 'maxlength' => 150]])
            ->add('siret', TextType::class, ['label' => 'SIRET', 'required' => false, 'attr' => [
                'inputmode' => 'numeric', 'maxlength' => 20, 'pattern' => '\s*(?:\d\s*){14}', 'data-invalid' => QuoteRequest::SIRET_INVALID,
            ]])
            ->add('billingAddress', TextType::class, ['label' => 'Adresse de facturation', 'required' => false, 'attr' => ['autocomplete' => 'street-address', 'maxlength' => 255]])
            ->add('access', TextareaType::class, ['label' => 'Accès au lieu', 'required' => false, 'attr' => ['maxlength' => 1000, 'rows' => 3]])
            ->add('source', ChoiceType::class, [
                'label' => 'Comment nous avez-vous connus ?',
                'required' => false,
                'placeholder' => 'Choisir',
                'choices' => array_flip(QuoteRequest::SOURCES),
                'invalid_message' => 'Choisissez une réponse de la liste.',
            ])
            ->add('acceptTerms', CheckboxType::class, ['mapped' => false, 'required' => false, 'constraints' => [new IsTrue(message: self::TERMS_MISSING)], 'attr' => ['data-missing' => self::TERMS_MISSING]])
            ->add('acceptPrivacy', CheckboxType::class, ['mapped' => false, 'required' => false, 'constraints' => [new IsTrue(message: self::PRIVACY_MISSING)], 'attr' => ['data-missing' => self::PRIVACY_MISSING]])
            // Champ piège (AD-26) : invisible pour une personne, rempli par les robots
            ->add('website', TextType::class, ['mapped' => false, 'required' => false, 'attr' => ['autocomplete' => 'off', 'tabindex' => '-1']])
            ->add('draft', HiddenType::class, ['mapped' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => QuoteRequest::class,
            'csrf_message' => 'La page a expiré. Rechargez-la, puis envoyez de nouveau votre demande.',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'demande';
    }
}
