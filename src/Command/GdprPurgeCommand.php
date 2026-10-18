<?php

namespace App\Command;

use App\Repository\DistanceCacheRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Purge RGPD (AD-13), sans effet si relancée : distances en cache de plus de 13 mois (story 3.5).
 * Cron sur le serveur, sous verrou pour ne jamais tourner deux fois en même temps :
 * `flock -n var/gdpr-purge.lock php bin/console app:gdpr:purge`.
 */
#[AsCommand(name: 'app:gdpr:purge', description: 'Supprime les données à ne plus garder (distances en cache de plus de 13 mois). Cron : flock -n var/gdpr-purge.lock php bin/console app:gdpr:purge')]
final class GdprPurgeCommand
{
    public function __construct(private readonly DistanceCacheRepository $distances)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $count = $this->distances->purgeOlderThan(new \DateTimeImmutable('-13 months'));
        $io->success(\sprintf('%d distance(s) en cache supprimée(s).', $count));

        return Command::SUCCESS;
    }
}
