<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Seul service de numérotation (AD-25) : « SDDEV-202610001 » = préfixe, année et mois d'émission (heure de Paris), 3 chiffres.
 * Compteur remis à 1 chaque mois, incrémenté sous `SELECT … FOR UPDATE` dans la transaction du document : deux demandes
 * simultanées attendent leur tour et n'ont jamais le même numéro.
 */
final class DocumentNumberer
{
    public const string QUOTE = 'SDDEV';
    private const int MAX = 999;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @throws \OverflowException au-delà de 999 documents dans le mois */
    public function next(string $type, ?\DateTimeImmutable $now = null): string
    {
        if (!$this->db->isTransactionActive()) {
            throw new \LogicException('Le numéro s\'attribue dans la transaction qui enregistre le document.');
        }
        $now = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Paris'));
        $key = [$type, (int) $now->format('Y'), (int) $now->format('n')];
        // Première demande du mois : ligne créée à 0 (une demande simultanée attend la fin de cette transaction)
        $this->db->executeStatement('INSERT IGNORE INTO document_counter (type, year, month, last) VALUES (?, ?, ?, 0)', $key);
        $last = (int) $this->db->fetchOne('SELECT last FROM document_counter WHERE type = ? AND year = ? AND month = ? FOR UPDATE', $key);
        if ($last >= self::MAX) {
            throw new \OverflowException(\sprintf('Numérotation %s pleine : %d documents déjà émis en %s. Le compteur repart à 1 le mois prochain.', $type, self::MAX, $now->format('m/Y')));
        }
        $this->db->executeStatement('UPDATE document_counter SET last = ? WHERE type = ? AND year = ? AND month = ?', [$last + 1, ...$key]);

        return \sprintf('%s-%s%03d', $type, $now->format('Ym'), $last + 1);
    }
}
