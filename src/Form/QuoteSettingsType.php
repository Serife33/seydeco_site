<?php

namespace App\Form;

use App\Service\SettingsProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Plus → Réglages → Devis (stories 3.1 + 3.2) : un champ par clé `Setting`. Vide = non renseigné. */
final class QuoteSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $days = ['unit' => 'jours', 'constraints' => [new Assert\Range(min: 1, max: 365, notInRangeMessage: 'Saisir un nombre de jours entre 1 et 365.')]];
        $money = [new Assert\PositiveOrZero(message: 'Le montant ne peut pas être négatif.'), new Assert\LessThanOrEqual(100000000, message: 'Le montant ne peut pas dépasser 1 000 000,00 €.')];
        $builder
            ->add(SettingsProvider::DEPOSIT_PERCENT, UnitType::class, [
                'label' => 'Acompte',
                'unit' => '%',
                'help' => 'Part du total demandée à la réservation, par exemple 50.',
                'constraints' => [new Assert\Range(min: 1, max: 100, notInRangeMessage: 'L\'acompte doit être entre 1 et 100 %.')],
            ])
            ->add(SettingsProvider::QUOTE_REMINDER_DAYS, UnitType::class, $days + [
                'label' => 'Relance',
                'help' => 'Nombre de jours après la validation d\'un devis pour relancer la cliente.',
            ])
            ->add(SettingsProvider::QUOTE_VALIDITY_DAYS, UnitType::class, $days + [
                'label' => 'Validité',
                'help' => 'Nombre de jours pendant lesquels un devis validé reste valable.',
            ])
            ->add(SettingsProvider::DEPOSIT_AMOUNT, AdminMoneyType::class, [
                'label' => 'Caution',
                'help' => 'Chèque de caution demandé avec une pièce d\'identité.',
                'constraints' => $money,
            ])
            ->add(SettingsProvider::CLEANING_FEE, AdminMoneyType::class, [
                'label' => 'Chaises rendues sans nettoyage',
                'unit' => '€ / chaise',
                'help' => 'Supplément proposé aux clientes. Vide : l\'option n\'est pas proposée.',
                'constraints' => $money,
            ])
            ->add(SettingsProvider::SLOT_WINDOW_DAYS, UnitType::class, [
                'label' => 'Créneaux autour de l\'événement',
                'unit' => 'jours',
                'help' => 'Retrait au plus tôt ce nombre de jours avant l\'événement, retour au plus tard ce nombre de jours après.',
                'constraints' => [new Assert\Range(min: 0, max: 30, notInRangeMessage: 'Saisir un nombre de jours entre 0 et 30.')],
            ])
            ->add(SettingsProvider::SLOT_WINDOW_SMALL_CHAIRS, UnitType::class, [
                'label' => 'Petite commande',
                'unit' => 'chaises',
                'help' => 'En dessous de ce nombre de chaises, la fenêtre ci-dessous s\'applique.',
                'constraints' => [new Assert\Range(min: 0, max: 100000, notInRangeMessage: 'Saisir un nombre entre 0 et 100 000.')],
            ])
            ->add(SettingsProvider::SLOT_WINDOW_SMALL_DAYS, UnitType::class, [
                'label' => 'Créneaux pour une petite commande',
                'unit' => 'jours',
                'help' => 'Avant et après l\'événement, pour une commande sous le seuil de chaises.',
                'constraints' => [new Assert\Range(min: 0, max: 30, notInRangeMessage: 'Saisir un nombre de jours entre 0 et 30.')],
            ])
            ->add(SettingsProvider::NOTIFICATION_EMAIL, EmailType::class, [
                'label' => 'Email des nouvelles demandes',
                'required' => false,
                'empty_data' => '',
                'help' => 'Reçoit un message à chaque nouvelle demande de devis.',
                'constraints' => [new Assert\Length(max: 180), new Assert\Email(message: 'Saisir un email, par exemple contact@seydeco.fr.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_message' => 'Session expirée, réessayez.',
            'constraints' => [new Assert\Callback(static function (array $data, ExecutionContextInterface $context): void {
                $reminder = $data[SettingsProvider::QUOTE_REMINDER_DAYS] ?? null;
                $validity = $data[SettingsProvider::QUOTE_VALIDITY_DAYS] ?? null;
                if (null !== $reminder && null !== $validity && $reminder >= $validity) {
                    $context->buildViolation('Le délai de relance doit être plus court que la durée de validité.')
                        ->atPath('['.SettingsProvider::QUOTE_REMINDER_DAYS.']')
                        ->addViolation();
                }
            })],
        ]);
    }
}
