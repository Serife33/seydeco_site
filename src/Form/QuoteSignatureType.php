<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Signature en ligne (story 3.16) : tracé du canvas (PNG en data URL), case des conditions et version affichée,
 * comparée par le contrôleur à la version en cours (AD-29).
 */
final class QuoteSignatureType extends AbstractType
{
    public const string IMAGE_MISSING = 'Tracez votre signature dans le cadre.';
    public const string TERMS_MISSING = 'Cochez cette case pour accepter les conditions de location.';
    private const string PREFIX = 'data:image/png;base64,';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('versionId', HiddenType::class)
            ->add('image', HiddenType::class, ['error_bubbling' => false, 'constraints' => [
                new Assert\NotBlank(message: self::IMAGE_MISSING),
                new Assert\Callback(self::checkImage(...)),
            ]])
            ->add('acceptTerms', CheckboxType::class, ['required' => false, 'constraints' => [new Assert\IsTrue(message: self::TERMS_MISSING)]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_message' => 'La page a expiré. Rechargez-la, puis signez de nouveau.',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'signature';
    }

    /** PNG en base64 seulement (500 Ko au plus), sinon même message que le tracé vide. */
    public static function png(?string $value): ?string
    {
        if (null === $value || !str_starts_with($value, self::PREFIX) || \strlen($value) > 700_000) {
            return null;
        }
        $binary = base64_decode(substr($value, \strlen(self::PREFIX)), true);
        $size = false === $binary ? false : @getimagesizefromstring($binary);

        return $size && \IMAGETYPE_PNG === $size[2] ? base64_encode($binary) : null;
    }

    private static function checkImage(mixed $value, ExecutionContextInterface $context): void
    {
        if (\is_string($value) && '' !== $value && null === self::png($value)) {
            $context->addViolation(self::IMAGE_MISSING);
        }
    }
}
