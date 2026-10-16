<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 1.3 : connexion au back-office.
 */
final class Version20261016220109 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compte admin (2FA email), file Messenger, compteurs anti-essais (cache DBAL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, auth_code VARCHAR(16) DEFAULT NULL, auth_code_expires_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_admin_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE cache_items (item_id VARBINARY(255) NOT NULL, item_data MEDIUMBLOB NOT NULL, item_lifetime INT UNSIGNED DEFAULT NULL, item_time INT UNSIGNED NOT NULL, PRIMARY KEY (item_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_user');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE cache_items');
    }
}
