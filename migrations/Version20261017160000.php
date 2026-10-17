<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stories 2.1 + 2.2 : coordonnées, réseaux (vides = non affichés) et « Site ouvert aux moteurs de recherche » (fermé par défaut).
 */
final class Version20261017160000 extends AbstractMigration
{
    private const array EMPTY_KEYS = ['phone', 'address', 'siret', 'instagram_url', 'tiktok_url', 'facebook_url', 'youtube_url', 'pinterest_url'];

    public function getDescription(): string
    {
        return 'Réglages : coordonnées, réseaux et ouverture aux moteurs de recherche';
    }

    public function up(Schema $schema): void
    {
        foreach (self::EMPTY_KEYS as $key) {
            $this->addSql("INSERT INTO setting (name, value) VALUES ('$key', '')");
        }
        $this->addSql("INSERT INTO setting (name, value) VALUES ('search_engines_open', '0')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM setting WHERE name IN ('".implode("', '", self::EMPTY_KEYS)."', 'search_engines_open')");
    }
}
