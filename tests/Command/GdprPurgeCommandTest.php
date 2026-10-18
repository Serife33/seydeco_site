<?php

namespace App\Tests\Command;

use App\Entity\DistanceCache;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** Story 3.5 : `app:gdpr:purge` supprime les distances en cache de plus de 13 mois ; relancée, elle ne fait rien de plus. */
final class GdprPurgeCommandTest extends KernelTestCase
{
    public function testPurgesDistancesOlderThanThirteenMonths(): void
    {
        $kernel = self::bootKernel();
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('DELETE FROM distance_cache');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new DistanceCache(str_repeat('a', 64), 1000, new \DateTimeImmutable('-14 months')));
        $em->persist(new DistanceCache(str_repeat('b', 64), 2000, new \DateTimeImmutable('-12 months')));
        $em->persist(new DistanceCache(str_repeat('c', 64), 3000));
        $em->flush();
        $tester = new CommandTester((new Application($kernel))->find('app:gdpr:purge'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('1 distance(s)', $tester->getDisplay());
        self::assertSame([2000, 3000], array_map('intval', $db->fetchFirstColumn('SELECT meters FROM distance_cache ORDER BY meters')));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('0 distance(s)', $tester->getDisplay());
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM distance_cache'));
    }
}
