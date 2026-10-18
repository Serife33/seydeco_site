<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Demandes de devis : demande, versions, lignes, compteur de numéros et statistiques anonymes (story 3.12)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document_counter (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(10) NOT NULL, year INT NOT NULL, month INT NOT NULL, last INT NOT NULL, UNIQUE INDEX uniq_document_counter (type, year, month), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quote_line (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, type VARCHAR(20) NOT NULL, label VARCHAR(255) NOT NULL, color_name VARCHAR(80) DEFAULT NULL, quantity INT DEFAULT NULL, unit_price INT DEFAULT NULL, amount INT NOT NULL, breakage_price INT DEFAULT NULL, loss_price INT DEFAULT NULL, version_id INT NOT NULL, product_id INT DEFAULT NULL, INDEX IDX_43F3EB7C4BBC2705 (version_id), INDEX IDX_43F3EB7C4584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quote_request (id INT AUTO_INCREMENT NOT NULL, number VARCHAR(20) NOT NULL, token VARCHAR(32) NOT NULL, stat_key VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL, phone VARCHAR(30) NOT NULL, email VARCHAR(180) NOT NULL, company_name VARCHAR(150) DEFAULT NULL, siret VARCHAR(14) DEFAULT NULL, billing_address VARCHAR(255) DEFAULT NULL, access LONGTEXT DEFAULT NULL, source VARCHAR(20) DEFAULT NULL, terms_accepted_at DATETIME NOT NULL, privacy_accepted_at DATETIME NOT NULL, created_at DATETIME NOT NULL, current_version_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_D478271B9407EE77 (current_version_id), UNIQUE INDEX uniq_quote_request_number (number), UNIQUE INDEX uniq_quote_request_token (token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quote_version (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, event_date DATE NOT NULL, start_at DATETIME NOT NULL, end_at DATETIME NOT NULL, mode VARCHAR(10) NOT NULL, collect TINYINT NOT NULL, installation TINYINT NOT NULL, cleaning TINYINT NOT NULL, address VARCHAR(255) DEFAULT NULL, pricing_context JSON NOT NULL, total INT NOT NULL, deposit INT NOT NULL, excludes_delivery TINYINT NOT NULL, created_at DATETIME NOT NULL, request_id INT NOT NULL, UNIQUE INDEX uniq_quote_version_number (request_id, number), INDEX IDX_50F37B47427EB8A5 (request_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stat_event (id INT AUTO_INCREMENT NOT NULL, stat_key VARCHAR(32) NOT NULL, type VARCHAR(30) NOT NULL, occurred_at DATETIME NOT NULL, source VARCHAR(20) DEFAULT NULL, pro TINYINT NOT NULL, amount INT DEFAULT NULL, products JSON DEFAULT NULL, UNIQUE INDEX uniq_stat_event (stat_key, type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE quote_line ADD CONSTRAINT FK_43F3EB7C4BBC2705 FOREIGN KEY (version_id) REFERENCES quote_version (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quote_line ADD CONSTRAINT FK_43F3EB7C4584665A FOREIGN KEY (product_id) REFERENCES product (id)');
        $this->addSql('ALTER TABLE quote_request ADD CONSTRAINT FK_D478271B9407EE77 FOREIGN KEY (current_version_id) REFERENCES quote_version (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE quote_version ADD CONSTRAINT FK_50F37B47427EB8A5 FOREIGN KEY (request_id) REFERENCES quote_request (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quote_line DROP FOREIGN KEY FK_43F3EB7C4BBC2705');
        $this->addSql('ALTER TABLE quote_line DROP FOREIGN KEY FK_43F3EB7C4584665A');
        $this->addSql('ALTER TABLE quote_request DROP FOREIGN KEY FK_D478271B9407EE77');
        $this->addSql('ALTER TABLE quote_version DROP FOREIGN KEY FK_50F37B47427EB8A5');
        $this->addSql('DROP TABLE document_counter');
        $this->addSql('DROP TABLE quote_line');
        $this->addSql('DROP TABLE quote_request');
        $this->addSql('DROP TABLE quote_version');
        $this->addSql('DROP TABLE stat_event');
    }
}
