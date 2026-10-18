<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 2.12 : date de dernière modification des contenus listés dans le sitemap (`lastmod`).
 * Valeur par défaut : la date d'insertion (lignes existantes, insertions SQL directes).
 */
final class Version20261018095714 extends AbstractMigration
{
    private const array TABLES = ['category', 'faq_item', 'pack', 'page', 'product', 'realisation', 'venue'];

    public function getDescription(): string
    {
        return 'Date de dernière modification des contenus (sitemap)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql("ALTER TABLE $table ADD updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql("ALTER TABLE $table DROP updated_at");
        }
    }
}
