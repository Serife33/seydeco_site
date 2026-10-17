<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Réglage modifiable par Serife (clé → valeur), lu uniquement via SettingsProvider. Valeurs initiales posées par migration. */
#[ORM\Entity]
class Setting
{
    #[ORM\Id]
    #[ORM\Column(length: 50)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $value;

    public function __construct(string $name, string $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
    }
}
