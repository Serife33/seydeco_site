<?php

namespace App\Tests\Service;

use App\Service\DistanceCalculatorInterface;
use App\Tests\Double\FakeDistanceCalculator;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Story 3.5 : cache des distances (clé SHA-256, sans adresse) et limite hors cache, devant la distance simulée. */
final class CachedDistanceCalculatorTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->db->executeStatement('DELETE FROM distance_cache');
        $this->depot('12 rue du Dépôt, 33530 Bassens');
        static::getContainer()->get('cache.rate_limiter_dbal')->clear();
        FakeDistanceCalculator::reset();
    }

    protected function tearDown(): void
    {
        $this->depot('');
        parent::tearDown();
    }

    private function depot(string $address): void
    {
        $this->db->executeStatement("UPDATE setting SET value = ? WHERE name = 'depot_address'", [$address]);
    }

    private function distance(): DistanceCalculatorInterface
    {
        return static::getContainer()->get(DistanceCalculatorInterface::class);
    }

    public function testSameAddressWrittenOtherwiseComesFromCache(): void
    {
        self::assertSame(10400, $this->distance()->metersFromDepot('3, Place de l\'Église — Bordeaux'));
        FakeDistanceCalculator::$meters = 99_999;
        self::assertSame(10400, $this->distance()->metersFromDepot('  3 PLACE DE L EGLISE   bordeaux '));
        self::assertSame(1, FakeDistanceCalculator::$calls);

        $rows = $this->db->fetchAllAssociative('SELECT * FROM distance_cache');
        self::assertCount(1, $rows);
        self::assertSame(hash('sha256', "12 rue du depot 33530 bassens\n3 place de l eglise bordeaux"), $rows[0]['cache_key']);
        self::assertSame(10400, (int) $rows[0]['meters']);
        self::assertNotNull($rows[0]['created_at']);
        self::assertStringNotContainsStringIgnoringCase('eglise', json_encode($rows));
    }

    public function testDepotChangeMissesCache(): void
    {
        $this->distance()->metersFromDepot('3 place de la Bourse, Bordeaux');
        $this->depot('1 avenue Neuve, 33000 Bordeaux');
        // Réglages relus : nouveau noyau
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->distance()->metersFromDepot('3 place de la Bourse, Bordeaux');

        self::assertSame(2, FakeDistanceCalculator::$calls);
    }

    public function testUnknownDistanceIsNotCached(): void
    {
        FakeDistanceCalculator::$meters = null;

        self::assertNull($this->distance()->metersFromDepot('Nulle part'));
        self::assertNull($this->distance()->metersFromDepot('Nulle part'));
        self::assertSame(2, FakeDistanceCalculator::$calls);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM distance_cache'));
    }

    public function testLimitOutsideCacheGivesUnknownDistanceWithoutCall(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            self::assertSame(10400, $this->distance()->metersFromDepot("$i rue de Test"));
        }
        self::assertNull($this->distance()->metersFromDepot('11 rue de Test'));
        self::assertSame(10, FakeDistanceCalculator::$calls);
        // Une adresse déjà en cache reste servie
        self::assertSame(10400, $this->distance()->metersFromDepot('1 rue de Test'));
    }

    public function testNoDepotAddressGivesUnknownDistance(): void
    {
        $this->depot('');

        self::assertNull($this->distance()->metersFromDepot('3 place de la Bourse'));
        self::assertSame(0, FakeDistanceCalculator::$calls);
    }
}
