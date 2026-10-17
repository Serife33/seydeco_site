<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 1.12 : prix d'une table quand assez de chaises sont louées (FR-39).
 */
final class Version20261017130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Produit : prix avec chaises (centimes, facultatif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD price_with_chairs INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP price_with_chairs');
    }
}
