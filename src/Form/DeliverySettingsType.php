<?php

namespace App\Form;

use App\Service\AddressNormalizer;
use App\Service\SettingsProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Plus → Réglages → Livraison (story 3.1) : un champ par clé `Setting`. Vide = non renseigné. */
final class DeliverySettingsType extends AbstractType
{
    public function __construct(private readonly AddressNormalizer $normalizer)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $count = fn (int $min) => [new Assert\Range(min: $min, max: 100000, notInRangeMessage: "Saisir un nombre entre $min et 100 000.")];
        $text = ['required' => false, 'empty_data' => ''];
        $builder
            ->add(SettingsProvider::DELIVERY_PRICE_PER_KM, AdminMoneyType::class, [
                'label' => 'Tarif au kilomètre',
                'unit' => '€ / km',
                'help' => 'Compté sur les kilomètres par la route, aller et retour de chaque camion.',
                'constraints' => [new Assert\PositiveOrZero(message: 'Le montant ne peut pas être négatif.'), new Assert\LessThanOrEqual(100000, message: 'Le montant ne peut pas dépasser 1 000,00 €.')],
            ])
            ->add(SettingsProvider::DELIVERY_MIN_CHAIRS, UnitType::class, ['label' => 'Minimum pour livrer', 'unit' => 'chaises', 'constraints' => $count(0)])
            ->add(SettingsProvider::DELIVERY_MAX_KM, UnitType::class, ['label' => 'Distance maximale', 'unit' => 'km', 'constraints' => $count(1)])
            ->add(SettingsProvider::TRUCK_CAPACITY, UnitType::class, [
                'label' => 'Capacité d\'un camion',
                'unit' => 'chaises',
                'help' => 'Au-delà, un voyage de plus est compté.',
                'constraints' => $count(1),
            ])
            ->add(SettingsProvider::DEPOT_ADDRESS, TextType::class, $text + [
                'label' => 'Adresse du dépôt',
                'help' => 'Point de départ du calcul des kilomètres. Elle n\'est pas affichée sur le site.',
                'attr' => ['autocomplete' => 'street-address'],
                'constraints' => [
                    new Assert\Length(max: 300),
                    new Assert\Callback(function (string $address, ExecutionContextInterface $context): void {
                        if ('' !== trim($address) && '' === $this->normalizer->normalize($address)) {
                            $context->addViolation('Saisir l\'adresse complète, par exemple 12 rue du Port, 33530 Bassens.');
                        }
                    }),
                ],
            ])
            ->add(SettingsProvider::DEPOT_CITY, TextType::class, $text + [
                'label' => 'Ville du dépôt',
                'help' => 'Affichée sur le site (« retrait au dépôt de Bassens »).',
                'constraints' => [new Assert\Length(max: 100)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_message' => 'Session expirée, réessayez.']);
    }
}
