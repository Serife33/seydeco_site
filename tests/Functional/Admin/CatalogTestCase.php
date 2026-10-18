<?php

namespace App\Tests\Functional\Admin;

use App\Entity\Category;
use App\Entity\PricingType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;

/** Connecté, catalogue vide. */
abstract class CatalogTestCase extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['home_hero', 'announcement', 'review', 'realisation_product', 'realisation_photo', 'realisation', 'venue', 'related_product', 'pack_item', 'pack', 'product_style', 'product_event_type', 'style', 'event_type', 'price_tier', 'product_photo', 'product_color', 'product', 'category'] as $table) {
            $db->executeStatement("TRUNCATE TABLE $table");
        }
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        (new Filesystem())->remove(static::getContainer()->getParameter('app.upload_dir'));
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
