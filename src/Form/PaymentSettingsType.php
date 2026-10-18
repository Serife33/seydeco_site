<?php

namespace App\Form;

use App\Service\SettingsProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Plus → Réglages → Paiement (story 3.2) : coordonnées bancaires et moyens de paiement acceptés. */
final class PaymentSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $text = ['required' => false, 'empty_data' => ''];
        $builder
            ->add(SettingsProvider::BANK_HOLDER, TextType::class, $text + ['label' => 'Titulaire du compte', 'constraints' => [new Assert\Length(max: 100)]])
            ->add(SettingsProvider::IBAN, TextType::class, $text + [
                'label' => 'IBAN',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
                'constraints' => [new Assert\Iban(message: 'Cet IBAN n\'est pas valide : vérifiez chaque caractère.')],
            ])
            ->add(SettingsProvider::BIC, TextType::class, $text + [
                'label' => 'BIC',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
                'constraints' => [new Assert\Bic(message: 'Ce BIC n\'est pas valide : il compte 8 ou 11 caractères.')],
            ])
            ->add(SettingsProvider::PAYMENT_METHODS, ChoiceType::class, [
                'label' => 'Moyens de paiement acceptés',
                'choices' => array_flip(SettingsProvider::PAYMENT_METHOD_LABELS),
                'expanded' => true,
                'multiple' => true,
                'constraints' => [new Assert\NotBlank(message: 'Cochez au moins un moyen de paiement.')],
            ]);
        // Stocké « virement,wero » dans `Setting`
        $builder->get(SettingsProvider::PAYMENT_METHODS)->addModelTransformer(new CallbackTransformer(
            fn (?string $value) => array_values(array_filter(explode(',', (string) $value))),
            fn (array $value) => implode(',', $value),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_message' => 'Session expirée, réessayez.']);
    }
}
