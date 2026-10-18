<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018163801 extends AbstractMigration
{
    private const string BEFORE = 'transmise au service de calcul de distance, uniquement pour estimer la livraison.';
    private const string AFTER = 'transmise à Google (Google Maps, service de calcul d\'itinéraire), uniquement pour calculer la distance par la route depuis notre dépôt et estimer la livraison.';

    public function getDescription(): string
    {
        return 'Cache des distances par la route, sans adresse en clair, et mention de Google dans la politique de confidentialité (story 3.5)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE distance_cache (id INT AUTO_INCREMENT NOT NULL, cache_key VARCHAR(64) NOT NULL, meters INT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_distance_cache_key (cache_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        // Texte provisoire de la story 2.3 : seulement la phrase concernée, le reste de la page n'est pas touché
        $this->addSql("UPDATE page SET content = REPLACE(content, ?, ?) WHERE slug = 'politique-de-confidentialite'", [self::BEFORE, self::AFTER]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE distance_cache');
        $this->addSql("UPDATE page SET content = REPLACE(content, ?, ?) WHERE slug = 'politique-de-confidentialite'", [self::AFTER, self::BEFORE]);
    }
}
