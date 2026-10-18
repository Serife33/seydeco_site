<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Signatures en ligne des devis : tracé, date, IP, PDF signé et son SHA-256 (story 3.16)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE signature (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(10) NOT NULL, signed_at DATETIME NOT NULL, ip VARCHAR(45) NOT NULL, image MEDIUMTEXT NOT NULL, pdf_path VARCHAR(100) NOT NULL, pdf_sha256 VARCHAR(64) NOT NULL, version_id INT NOT NULL, UNIQUE INDEX uniq_signature_version_type (version_id, type), INDEX IDX_AE8801414BBC2705 (version_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE signature ADD CONSTRAINT FK_AE8801414BBC2705 FOREIGN KEY (version_id) REFERENCES quote_version (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE signature DROP FOREIGN KEY FK_AE8801414BBC2705');
        $this->addSql('DROP TABLE signature');
    }
}
