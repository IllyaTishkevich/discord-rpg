<?php

namespace App\Entity;

use App\Enum\AbilityType;
use App\Repository\AbilityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Catalog row for one of the 4 fixed combat abilities (App\Enum\AbilityType)
 * — exists so classes, characters and equipment can each reference "which
 * abilities can this side use", the same way Bit works for the coin
 * definitions themselves. There's normally exactly one Ability row per
 * AbilityType (enforced by a unique constraint), seeded once
 * (SeedAbilitiesCommand) and never really needing more.
 *
 * Availability during a real battle: Character::hasAbilityType() checks the
 * union of the character's class's abilities and its own directly-granted
 * ones (see CharacterClass::$abilities, Character::$abilities) — see
 * docs/BATTLE_RULES.md §3.1.
 */
#[ORM\Entity(repositoryClass: AbilityRepository::class)]
class Ability
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: AbilityType::class, unique: true)]
    private AbilityType $type;

    public function __construct(AbilityType $type)
    {
        $this->type = $type;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): AbilityType
    {
        return $this->type;
    }

    public function setType(AbilityType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function __toString(): string
    {
        return $this->type->label();
    }
}
