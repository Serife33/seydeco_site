<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stories 1.4 + 1.5 : catégories, produits, styles et types d'événement.
 */
final class Version20261017070540 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catégories, produits, styles et types d\'événement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, slug VARCHAR(120) NOT NULL, position INT NOT NULL, visible TINYINT NOT NULL, pricing_type VARCHAR(10) NOT NULL, refurbish_delay INT NOT NULL, install_price INT DEFAULT NULL, breakage_price INT DEFAULT NULL, loss_price INT DEFAULT NULL, return_instructions LONGTEXT DEFAULT NULL, UNIQUE INDEX uniq_category_name (name), UNIQUE INDEX uniq_category_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE event_type (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, UNIQUE INDEX uniq_event_type_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(170) NOT NULL, dimensions VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, seats INT DEFAULT NULL, min_qty INT NOT NULL, stock INT NOT NULL, created_at DATETIME NOT NULL, visible TINYINT NOT NULL, category_id INT NOT NULL, UNIQUE INDEX uniq_product_slug (slug), INDEX IDX_D34A04AD12469DE2 (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product_style (product_id INT NOT NULL, style_id INT NOT NULL, INDEX IDX_92E1C3364584665A (product_id), INDEX IDX_92E1C336BACD6074 (style_id), PRIMARY KEY (product_id, style_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product_event_type (product_id INT NOT NULL, event_type_id INT NOT NULL, INDEX IDX_7244A94584665A (product_id), INDEX IDX_7244A9401B253C (event_type_id), PRIMARY KEY (product_id, event_type_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE style (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, UNIQUE INDEX uniq_style_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD12469DE2 FOREIGN KEY (category_id) REFERENCES category (id)');
        $this->addSql('ALTER TABLE product_style ADD CONSTRAINT FK_92E1C3364584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_style ADD CONSTRAINT FK_92E1C336BACD6074 FOREIGN KEY (style_id) REFERENCES style (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_event_type ADD CONSTRAINT FK_7244A94584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_event_type ADD CONSTRAINT FK_7244A9401B253C FOREIGN KEY (event_type_id) REFERENCES event_type (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04AD12469DE2');
        $this->addSql('ALTER TABLE product_style DROP FOREIGN KEY FK_92E1C3364584665A');
        $this->addSql('ALTER TABLE product_style DROP FOREIGN KEY FK_92E1C336BACD6074');
        $this->addSql('ALTER TABLE product_event_type DROP FOREIGN KEY FK_7244A94584665A');
        $this->addSql('ALTER TABLE product_event_type DROP FOREIGN KEY FK_7244A9401B253C');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE event_type');
        $this->addSql('DROP TABLE product');
        $this->addSql('DROP TABLE product_style');
        $this->addSql('DROP TABLE product_event_type');
        $this->addSql('DROP TABLE style');
    }
}
