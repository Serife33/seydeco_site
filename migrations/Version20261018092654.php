<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stories 2.7 + 2.8 : annonces du bandeau, avis Google et lien vers la fiche Google (réglage).
 */
final class Version20261018092654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Annonces, avis et lien des avis Google';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE announcement (id INT AUTO_INCREMENT NOT NULL, text VARCHAR(120) NOT NULL, link VARCHAR(500) DEFAULT NULL, starts_on DATE NOT NULL, ends_on DATE NOT NULL, position INT NOT NULL, visible TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE review (id INT AUTO_INCREMENT NOT NULL, author VARCHAR(60) NOT NULL, rating SMALLINT NOT NULL, text LONGTEXT NOT NULL, reviewed_on DATE NOT NULL, position INT NOT NULL, visible TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql("INSERT INTO setting (name, value) VALUES ('google_reviews_url', '')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE announcement');
        $this->addSql('DROP TABLE review');
        $this->addSql("DELETE FROM setting WHERE name = 'google_reviews_url'");
    }
}
