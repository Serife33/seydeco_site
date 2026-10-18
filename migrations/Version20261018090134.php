<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stories 2.5 + 2.6 : réalisations (photos, produits utilisés) et lieux partenaires.
 */
final class Version20261018090134 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Réalisations et lieux partenaires';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE realisation (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(150) NOT NULL, slug VARCHAR(170) NOT NULL, event_date DATE NOT NULL, venue_name VARCHAR(150) NOT NULL, city VARCHAR(100) NOT NULL, text LONGTEXT DEFAULT NULL, position INT NOT NULL, visible TINYINT NOT NULL, video_file VARCHAR(100) DEFAULT NULL, video_url VARCHAR(255) DEFAULT NULL, video_thumbnail VARCHAR(100) DEFAULT NULL, event_type_id INT DEFAULT NULL, venue_id INT DEFAULT NULL, UNIQUE INDEX uniq_realisation_slug (slug), INDEX IDX_EAA5610E401B253C (event_type_id), INDEX IDX_EAA5610E40A73EBA (venue_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE realisation_product (realisation_id INT NOT NULL, product_id INT NOT NULL, INDEX IDX_B4FCEF90B685E551 (realisation_id), INDEX IDX_B4FCEF904584665A (product_id), PRIMARY KEY (realisation_id, product_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE realisation_photo (id INT AUTO_INCREMENT NOT NULL, filename VARCHAR(100) NOT NULL, alt VARCHAR(255) NOT NULL, position INT NOT NULL, realisation_id INT NOT NULL, INDEX IDX_DE17EB48B685E551 (realisation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE venue (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(170) NOT NULL, city VARCHAR(100) NOT NULL, address VARCHAR(255) DEFAULT NULL, text LONGTEXT DEFAULT NULL, photo VARCHAR(100) NOT NULL, photo_alt VARCHAR(255) NOT NULL, website VARCHAR(255) DEFAULT NULL, position INT NOT NULL, visible TINYINT NOT NULL, UNIQUE INDEX uniq_venue_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE realisation ADD CONSTRAINT FK_EAA5610E401B253C FOREIGN KEY (event_type_id) REFERENCES event_type (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE realisation ADD CONSTRAINT FK_EAA5610E40A73EBA FOREIGN KEY (venue_id) REFERENCES venue (id)');
        $this->addSql('ALTER TABLE realisation_product ADD CONSTRAINT FK_B4FCEF90B685E551 FOREIGN KEY (realisation_id) REFERENCES realisation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE realisation_product ADD CONSTRAINT FK_B4FCEF904584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE realisation_photo ADD CONSTRAINT FK_DE17EB48B685E551 FOREIGN KEY (realisation_id) REFERENCES realisation (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE realisation DROP FOREIGN KEY FK_EAA5610E401B253C');
        $this->addSql('ALTER TABLE realisation DROP FOREIGN KEY FK_EAA5610E40A73EBA');
        $this->addSql('ALTER TABLE realisation_product DROP FOREIGN KEY FK_B4FCEF90B685E551');
        $this->addSql('ALTER TABLE realisation_product DROP FOREIGN KEY FK_B4FCEF904584665A');
        $this->addSql('ALTER TABLE realisation_photo DROP FOREIGN KEY FK_DE17EB48B685E551');
        $this->addSql('DROP TABLE realisation');
        $this->addSql('DROP TABLE realisation_product');
        $this->addSql('DROP TABLE realisation_photo');
        $this->addSql('DROP TABLE venue');
    }
}
