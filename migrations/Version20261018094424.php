<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stories 2.9 + 2.10 : haut de l'accueil (publié + brouillon), produits mis en avant,
 * année de création et texte de l'encadré L'entreprise (réglages).
 */
final class Version20261018094424 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Haut de l\'accueil, incontournables, année de création et texte L\'entreprise';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE home_hero (id INT AUTO_INCREMENT NOT NULL, draft TINYINT NOT NULL, title VARCHAR(60) NOT NULL, text VARCHAR(160) NOT NULL, button_label VARCHAR(40) NOT NULL, button_link VARCHAR(255) NOT NULL, photo VARCHAR(100) DEFAULT NULL, photo_alt VARCHAR(255) DEFAULT NULL, photo2 VARCHAR(100) DEFAULT NULL, photo2_alt VARCHAR(255) DEFAULT NULL, UNIQUE INDEX uniq_home_hero_draft (draft), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE product ADD featured TINYINT NOT NULL, ADD featured_position INT NOT NULL');
        $this->addSql("INSERT INTO setting (name, value) VALUES ('founded_year', '2026'), ('company_text', ?)", [
            'Seydeco loue chaises, tables, nappes, art de la table et décoration pour vos mariages et réceptions. Chaque commande est préparée et vérifiée avec soin, et nous restons joignables avant comme après votre événement.',
        ]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE home_hero');
        $this->addSql('ALTER TABLE product DROP featured, DROP featured_position');
        $this->addSql("DELETE FROM setting WHERE name IN ('founded_year', 'company_text')");
    }
}
