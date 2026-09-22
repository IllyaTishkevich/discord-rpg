<?php

namespace App\Entity;

use App\Repository\CharacterEquipmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Records that a character bought a given piece of equipment. The effect
 * itself (a new Bit, or a maxHp increase) is applied once at purchase time
 * directly onto the Character — this row is purely a purchase record for
 * the inventory screen.
 */
#[ORM\Entity(repositoryClass: CharacterEquipmentRepository::class)]
class CharacterEquipment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Character $character;

    #[ORM\ManyToOne(targetEntity: Equipment::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Equipment $equipment;

    #[ORM\Column]
    private \DateTimeImmutable $purchasedAt;

    public function __construct(Character $character, Equipment $equipment)
    {
        $this->character = $character;
        $this->equipment = $equipment;
        $this->purchasedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function getEquipment(): Equipment
    {
        return $this->equipment;
    }

    public function getPurchasedAt(): \DateTimeImmutable
    {
        return $this->purchasedAt;
    }
}
