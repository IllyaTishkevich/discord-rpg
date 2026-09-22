<?php

namespace App\Entity;

use App\Repository\CharacterClassRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Reference entity describing a playable class: base stats and the starter
 * set of bits every new character of this class receives.
 *
 * `starterBits` is a JSON array of two-sided bit definitions, e.g.
 * [{"faceA": "attack", "faceB": "defense"}, {"faceA": "attack", "faceB": "action"}].
 * Faces are one of: attack, defense, action (matches Sprint 2 Bit entity).
 */
#[ORM\Entity(repositoryClass: CharacterClassRepository::class)]
class CharacterClass
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $code;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private int $baseHp;

    #[ORM\Column]
    private int $baseEnergy;

    #[ORM\Column(type: 'json')]
    private array $starterBits = [];

    #[ORM\OneToMany(mappedBy: 'characterClass', targetEntity: Character::class)]
    private Collection $characters;

    public function __construct(string $code, string $name, int $baseHp, int $baseEnergy, array $starterBits)
    {
        $this->code = $code;
        $this->name = $name;
        $this->baseHp = $baseHp;
        $this->baseEnergy = $baseEnergy;
        $this->starterBits = $starterBits;
        $this->characters = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getBaseHp(): int
    {
        return $this->baseHp;
    }

    public function getBaseEnergy(): int
    {
        return $this->baseEnergy;
    }

    public function getStarterBits(): array
    {
        return $this->starterBits;
    }
}
