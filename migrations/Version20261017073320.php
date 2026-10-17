<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stories 1.8 + 1.11 : réglages (AD-12), avec le numéro WhatsApp/téléphone.
 */
final class Version20261017073320 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Réglages : numéro WhatsApp/téléphone';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE setting (name VARCHAR(50) NOT NULL, value LONGTEXT NOT NULL, PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4');
        // Numéro à renseigner par Serife (Réglages, Epic 3) ; vide = lien WhatsApp non affiché
        $this->addSql("INSERT INTO setting (name, value) VALUES ('whatsapp_phone', '')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE setting');
    }
}
