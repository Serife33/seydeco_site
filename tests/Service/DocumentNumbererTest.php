<?php

namespace App\Tests\Service;

use App\Service\DocumentNumberer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Story 3.12 (AD-25) : SDDEV-AAAAMMNNN, remis à 1 chaque mois, sous verrou, refus clair au-delà de 999. Vraie base de test. */
final class DocumentNumbererTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->db->executeStatement('TRUNCATE TABLE document_counter');
    }

    private function next(\DateTimeImmutable $now, ?Connection $db = null): string
    {
        $db ??= $this->db;

        return $db->transactional(fn () => (new DocumentNumberer($db))->next(DocumentNumberer::QUOTE, $now));
    }

    public function testFirstOfMonthThenFollowing(): void
    {
        $now = new \DateTimeImmutable('2026-10-18 10:00', new \DateTimeZone('Europe/Paris'));

        self::assertSame('SDDEV-202610001', $this->next($now));
        self::assertSame('SDDEV-202610002', $this->next($now));
    }

    public function testCounterRestartsEachMonthInParisTime(): void
    {
        self::assertSame('SDDEV-202610001', $this->next(new \DateTimeImmutable('2026-10-31 12:00', new \DateTimeZone('Europe/Paris'))));
        self::assertSame('SDDEV-202610002', $this->next(new \DateTimeImmutable('2026-10-31 20:00', new \DateTimeZone('Europe/Paris'))));
        // 31/10 à 23 h 30 UTC = 1er novembre à 0 h 30 à Paris
        self::assertSame('SDDEV-202611001', $this->next(new \DateTimeImmutable('2026-10-31 23:30', new \DateTimeZone('UTC'))));
        self::assertSame('SDDEV-202711001', $this->next(new \DateTimeImmutable('2027-11-02 09:00', new \DateTimeZone('Europe/Paris'))));
    }

    public function testRefusesAfter999(): void
    {
        $now = new \DateTimeImmutable('2026-10-18 10:00', new \DateTimeZone('Europe/Paris'));
        $this->db->executeStatement("INSERT INTO document_counter (type, year, month, last) VALUES ('SDDEV', 2026, 10, 998)");

        self::assertSame('SDDEV-202610999', $this->next($now));
        try {
            $this->next($now);
            self::fail('Le 1 000e numéro doit être refusé.');
        } catch (\OverflowException $e) {
            self::assertSame('Numérotation SDDEV pleine : 999 documents déjà émis en 10/2026. Le compteur repart à 1 le mois prochain.', $e->getMessage());
        }
        self::assertSame(999, (int) $this->db->fetchOne('SELECT last FROM document_counter'));
    }

    public function testSimultaneousRequestsWaitForTheLock(): void
    {
        $now = new \DateTimeImmutable('2026-10-18 10:00', new \DateTimeZone('Europe/Paris'));
        $this->next($now);
        // Deuxième connexion = une autre requête en parallèle ; elle n'attend qu'une seconde
        $other = DriverManager::getConnection($this->db->getParams());
        $other->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');

        $this->db->beginTransaction();
        self::assertSame('SDDEV-202610002', (new DocumentNumberer($this->db))->next(DocumentNumberer::QUOTE, $now));
        try {
            $this->next($now, $other);
            self::fail('La seconde demande doit attendre la fin de la première.');
        } catch (LockWaitTimeoutException) {
        }
        $this->db->commit();

        // Le verrou levé, elle obtient le numéro suivant, jamais le même
        self::assertSame('SDDEV-202610003', $this->next($now, $other));
        $other->close();
    }

    public function testOnlyInsideTheDocumentTransaction(): void
    {
        $this->expectException(\LogicException::class);
        (new DocumentNumberer($this->db))->next(DocumentNumberer::QUOTE);
    }
}
