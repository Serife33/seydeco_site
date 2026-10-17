<?php

namespace App\Entity;

use App\Repository\EventTypeRepository;
use Doctrine\ORM\Mapping as ORM;

/** Liste complétée par Serife depuis le formulaire produit. */
#[ORM\Entity(repositoryClass: EventTypeRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_event_type_name', fields: ['name'])]
class EventType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
