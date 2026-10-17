<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stories 1.14 + 1.15 : packs (produits + quantités) et « Souvent loué avec ».
 */
final class Version20261017150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Packs et produits souvent loués avec';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pack (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(170) NOT NULL, description LONGTEXT DEFAULT NULL, photo VARCHAR(100) NOT NULL, photo_alt VARCHAR(255) NOT NULL, position INT NOT NULL, visible TINYINT NOT NULL, UNIQUE INDEX uniq_pack_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE pack_item (id INT AUTO_INCREMENT NOT NULL, quantity INT NOT NULL, pack_id INT NOT NULL, product_id INT NOT NULL, INDEX IDX_569A7CA71919B217 (pack_id), INDEX IDX_569A7CA74584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE related_product (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, product_id INT NOT NULL, related_id INT NOT NULL, INDEX IDX_EC53CE084584665A (product_id), INDEX IDX_EC53CE084162C001 (related_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE pack_item ADD CONSTRAINT FK_569A7CA71919B217 FOREIGN KEY (pack_id) REFERENCES pack (id)');
        $this->addSql('ALTER TABLE pack_item ADD CONSTRAINT FK_569A7CA74584665A FOREIGN KEY (product_id) REFERENCES product (id)');
        $this->addSql('ALTER TABLE related_product ADD CONSTRAINT FK_EC53CE084584665A FOREIGN KEY (product_id) REFERENCES product (id)');
        $this->addSql('ALTER TABLE related_product ADD CONSTRAINT FK_EC53CE084162C001 FOREIGN KEY (related_id) REFERENCES product (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pack_item DROP FOREIGN KEY FK_569A7CA71919B217');
        $this->addSql('ALTER TABLE pack_item DROP FOREIGN KEY FK_569A7CA74584665A');
        $this->addSql('ALTER TABLE related_product DROP FOREIGN KEY FK_EC53CE084584665A');
        $this->addSql('ALTER TABLE related_product DROP FOREIGN KEY FK_EC53CE084162C001');
        $this->addSql('DROP TABLE pack');
        $this->addSql('DROP TABLE pack_item');
        $this->addSql('DROP TABLE related_product');
    }
}
