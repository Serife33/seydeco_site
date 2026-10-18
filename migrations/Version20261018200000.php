<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Story 3.10 : fenêtre de créneaux autour de l'événement (2 jours, 1 jour sous 50 chaises), réglable dans Réglages → Devis. */
final class Version20261018200000 extends AbstractMigration
{
    private const array SETTINGS = [
        'slot_window_days' => '2',
        'slot_window_small_days' => '1',
        'slot_window_small_chairs' => '50',
    ];

    public function getDescription(): string
    {
        return 'Réglages de la fenêtre de créneaux autour de l\'événement';
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
