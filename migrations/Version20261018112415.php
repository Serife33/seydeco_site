<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stories 3.1 + 3.2 : réglages Devis, Livraison, Conditions et Paiement (clés `Setting`).
 * Valeurs par défaut : supplément nettoyage 0,50 €, camion de 170 chaises, trois moyens de paiement ;
 * le reste est saisi par Serife. L'acompte reste en % (« 50 »), converti au calcul.
 */
final class Version20261018112415 extends AbstractMigration
{
    private const array SETTINGS = [
        'quote_reminder_days' => '',
        'quote_validity_days' => '',
        'notification_email' => '',
        'cleaning_fee' => '50',
        'truck_capacity' => '170',
        'depot_address' => '',
        'return_terms' => '',
        'pickup_terms' => '',
        'fuel_terms' => '',
        'bank_holder' => '',
        'iban' => '',
        'bic' => '',
        'payment_methods' => 'virement,especes,wero',
    ];

    public function getDescription(): string
    {
        return 'Réglages devis, livraison, conditions et paiement';
    }

    public function up(Schema $schema): void
    {
        foreach (self::SETTINGS as $name => $value) {
            $this->addSql('INSERT INTO setting (name, value) VALUES (?, ?)', [$name, $value]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM setting WHERE name IN ('".implode("', '", array_keys(self::SETTINGS))."')");
    }
}
