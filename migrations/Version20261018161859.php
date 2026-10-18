<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018161859 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Créneaux au dépôt et fermetures exceptionnelles (story 3.3)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE depot_closure (id INT AUTO_INCREMENT NOT NULL, starts_on DATE NOT NULL, ends_on DATE NOT NULL, reason VARCHAR(120) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE slot (id INT AUTO_INCREMENT NOT NULL, weekday SMALLINT NOT NULL, starts_at TIME NOT NULL, ends_at TIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE depot_closure');
        $this->addSql('DROP TABLE slot');
    }
}
