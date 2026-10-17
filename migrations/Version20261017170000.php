<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stories 2.3 + 2.4 : pages éditables (les six pages de base, contenu provisoire « à compléter »), FAQ,
 * réglages email (page Contact) et pourcentage d'acompte (Comment ça marche).
 * Les textes légaux définitifs viennent de Serife et de son juriste.
 */
final class Version20261017170000 extends AbstractMigration
{
    private const string TODO = '**À compléter par Serife.** Ce texte provisoire sera remplacé avant l\'ouverture du site.';

    public function getDescription(): string
    {
        return 'Pages, FAQ, réglages email et acompte';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE faq_item (id INT AUTO_INCREMENT NOT NULL, question VARCHAR(255) NOT NULL, answer LONGTEXT NOT NULL, theme VARCHAR(100) DEFAULT NULL, position INT NOT NULL, visible TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE page (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(150) NOT NULL, slug VARCHAR(170) NOT NULL, content LONGTEXT NOT NULL, description VARCHAR(160) DEFAULT NULL, photo VARCHAR(100) DEFAULT NULL, photo_alt VARCHAR(255) DEFAULT NULL, position INT NOT NULL, visible TINYINT NOT NULL, UNIQUE INDEX uniq_page_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $pages = [
            ['À propos', 'a-propos', "## Qui sommes-nous ?\n\nSeydeco loue du matériel de réception pour les mariages et les événements : chaises, tables, vaisselle et décoration.", 'Qui est derrière Seydeco ? Découvrez Serife et la location de matériel de réception pour mariages et événements.'],
            ['Contact', 'contact', 'Une question sur la location ou une date à réserver ? Écrivez-nous sur WhatsApp ou appelez-nous.', 'Contacter Seydeco : WhatsApp, téléphone et email pour louer du matériel de réception pour votre mariage ou événement.'],
            ['Mentions légales', 'mentions-legales', "## Éditeur du site\n\nSeydeco.\n\n## Hébergement\n\no2switch.", 'Mentions légales du site Seydeco : éditeur, hébergement et informations sur la société de location de matériel de réception.'],
            ['Conditions générales de location', 'conditions-generales-de-location', '## Réservation, acompte et caution', 'Conditions générales de location Seydeco : réservation, acompte, caution, retrait, livraison et retour du matériel loué.'],
            ['Politique de confidentialité', 'politique-de-confidentialite', "## Données collectées\n\nL'adresse du lieu de votre événement est transmise au service de calcul de distance, uniquement pour estimer la livraison.", 'Politique de confidentialité Seydeco : données collectées, utilisation, durée de conservation et vos droits.'],
            ['Gestion des cookies', 'gestion-des-cookies', '## Cookies utilisés par le site', 'Gestion des cookies sur le site Seydeco : cookies essentiels, mesure d\'audience et vidéos, et comment changer votre choix.'],
        ];
        foreach ($pages as $position => [$title, $slug, $content, $description]) {
            $this->addSql(
                'INSERT INTO page (title, slug, content, description, position, visible) VALUES (?, ?, ?, ?, ?, 1)',
                [$title, $slug, self::TODO."\n\n".$content, $description, $position + 1],
            );
        }
        $this->addSql("INSERT INTO setting (name, value) VALUES ('email', ''), ('deposit_percent', '50')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE faq_item');
        $this->addSql('DROP TABLE page');
        $this->addSql("DELETE FROM setting WHERE name IN ('email', 'deposit_percent')");
    }
}
