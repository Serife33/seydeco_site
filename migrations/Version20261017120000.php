<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stories 1.9 + 1.10 : réglages du Bloc infos pratiques de la fiche produit (AD-12).
 */
final class Version20261017120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Réglages : caution, tarif au km, minimum de chaises, distance max, ville du dépôt';
    }

    public function up(Schema $schema): void
    {
        // Caution vide = « Montant indiqué dans votre devis » ; montants en centimes
        $this->addSql("INSERT INTO setting (name, value) VALUES ('deposit_amount', ''), ('delivery_price_per_km', '100'), ('delivery_min_chairs', '80'), ('delivery_max_km', '70'), ('depot_city', 'Bassens')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM setting WHERE name IN ('deposit_amount', 'delivery_price_per_km', 'delivery_min_chairs', 'delivery_max_km', 'depot_city')");
    }
}
