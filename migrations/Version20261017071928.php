<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stories 1.6 + 1.7 : paliers de prix, casse/perte et consignes, photos, coloris et vidéo d'un produit.
 */
final class Version20261017071928 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paliers de prix, casse/perte, consignes, photos, coloris et vidéo des produits';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE price_tier (id INT AUTO_INCREMENT NOT NULL, min_qty INT NOT NULL, price INT NOT NULL, product_id INT NOT NULL, INDEX IDX_59CC65304584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product_color (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(60) NOT NULL, hex VARCHAR(7) NOT NULL, product_id INT NOT NULL, INDEX IDX_C70A33B54584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product_photo (id INT AUTO_INCREMENT NOT NULL, filename VARCHAR(100) NOT NULL, alt VARCHAR(255) NOT NULL, position INT NOT NULL, product_id INT NOT NULL, color_id INT DEFAULT NULL, INDEX IDX_B5EBFF444584665A (product_id), INDEX IDX_B5EBFF447ADA1FB5 (color_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE price_tier ADD CONSTRAINT FK_59CC65304584665A FOREIGN KEY (product_id) REFERENCES product (id)');
        $this->addSql('ALTER TABLE product_color ADD CONSTRAINT FK_C70A33B54584665A FOREIGN KEY (product_id) REFERENCES product (id)');
        $this->addSql('ALTER TABLE product_photo ADD CONSTRAINT FK_B5EBFF444584665A FOREIGN KEY (product_id) REFERENCES product (id)');
        $this->addSql('ALTER TABLE product_photo ADD CONSTRAINT FK_B5EBFF447ADA1FB5 FOREIGN KEY (color_id) REFERENCES product_color (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE product ADD breakage_price INT DEFAULT NULL, ADD loss_price INT DEFAULT NULL, ADD return_instructions LONGTEXT DEFAULT NULL, ADD video_file VARCHAR(100) DEFAULT NULL, ADD video_url VARCHAR(255) DEFAULT NULL, ADD video_thumbnail VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE price_tier DROP FOREIGN KEY FK_59CC65304584665A');
        $this->addSql('ALTER TABLE product_color DROP FOREIGN KEY FK_C70A33B54584665A');
        $this->addSql('ALTER TABLE product_photo DROP FOREIGN KEY FK_B5EBFF444584665A');
        $this->addSql('ALTER TABLE product_photo DROP FOREIGN KEY FK_B5EBFF447ADA1FB5');
        $this->addSql('DROP TABLE price_tier');
        $this->addSql('DROP TABLE product_color');
        $this->addSql('DROP TABLE product_photo');
        $this->addSql('ALTER TABLE product DROP breakage_price, DROP loss_price, DROP return_instructions, DROP video_file, DROP video_url, DROP video_thumbnail');
    }
}
