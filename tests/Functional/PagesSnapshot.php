<?php

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;

/** Les six pages de base viennent de la migration : un test qui les modifie les remet telles quelles. */
trait PagesSnapshot
{
    /** @var list<array<string, mixed>> */
    private array $pagesSnapshot = [];

    private function snapshotPages(Connection $db): void
    {
        $this->pagesSnapshot = $db->fetchAllAssociative('SELECT * FROM page');
        $db->executeStatement('TRUNCATE TABLE faq_item');
    }

    private function restorePages(Connection $db): void
    {
        $db->executeStatement('TRUNCATE TABLE page');
        $db->executeStatement('TRUNCATE TABLE faq_item');
        foreach ($this->pagesSnapshot as $row) {
            $db->insert('page', $row);
        }
    }
}
