<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Dernier numéro attribué par type de document et par mois (AD-25) ; écrit seulement par DocumentNumberer, sous verrou. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_document_counter', columns: ['type', 'year', 'month'])]
class DocumentCounter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private string $type;

    #[ORM\Column]
    private int $year;

    #[ORM\Column]
    private int $month;

    #[ORM\Column]
    private int $last = 0;

    public function __construct(string $type, int $year, int $month)
    {
        $this->type = $type;
        $this->year = $year;
        $this->month = $month;
    }

    public function getLast(): int
    {
        return $this->last;
    }
}
