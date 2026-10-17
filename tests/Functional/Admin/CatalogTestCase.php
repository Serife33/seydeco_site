<?php

namespace App\Tests\Functional\Admin;

use App\Entity\Category;
use App\Entity\PricingType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/** Connecté, catalogue vide. */
abstract class CatalogTestCase extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['product_style', 'product_event_type', 'style', 'event_type', 'product', 'category'] as $table) {
            $db->executeStatement("TRUNCATE TABLE $table");
        }
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $this->loginFully();
    }

    protected function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em;
    }

    protected function createCategory(string $name, int $position, PricingType $type = PricingType::Other): Category
    {
        $category = new Category();
        $category->setName($name);
        $category->initSlug(strtolower($name));
        $category->setPosition($position);
        $category->setPricingType($type);
        $em = $this->em();
        $em->persist($category);
        $em->flush();

        return $category;
    }
}
