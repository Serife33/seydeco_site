<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 1.13 : réglage « Devis en ligne », désactivé jusqu'à l'Epic 3 (site ouvert en vitrine).
 */
final class Version20261017140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Réglage « Devis en ligne » (désactivé par défaut)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO setting (name, value) VALUES ('quote_online', '0')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM setting WHERE name = 'quote_online'");
    }
}
