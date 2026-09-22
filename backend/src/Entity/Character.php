<?php

namespace App\Entity;

use App\Repository\CharacterRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CharacterRepository::class)]
class Character
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'character', targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private User $user;

    #[ORM\ManyToOne(targetEntity: CharacterClass::class)]
    #[ORM\JoinColumn(nullable: false)]
    private CharacterClass $characterClass;

    #[ORM\Column]
    private int $hp;

    #[ORM\Column]
    private int $maxHp;

    #[ORM\Column]
    private int $energy;

    #[ORM\Column]
    private int $maxEnergy;

    #[ORM\Column]
    private int $level = 1;

    #[ORM\Column]
    private int $xp = 0;

    #[ORM\Column]
    private int $coins = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, CharacterClass $characterClass)
    {
        $this->user = $user;
        $this->characterClass = $characterClass;
        $this->maxHp = $characterClass->getBaseHp();
        $this->hp = $this->maxHp;
        $this->maxEnergy = $characterClass->getBaseEnergy();
        $this->energy = $this->maxEnergy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCharacterClass(): CharacterClass
    {
        return $this->characterClass;
    }

    public function getHp(): int
    {
        return $this->hp;
    }

    public function setHp(int $hp): static
    {
        $this->hp = max(0, min($hp, $this->maxHp));

        return $this;
    }

    public function getMaxHp(): int
    {
        return $this->maxHp;
    }

    /**
     * Permanently raises the HP cap (e.g. from equipment) and heals by the
     * same amount, matching how "+max HP" items conventionally work.
     */
    public function increaseMaxHp(int $amount): static
    {
        $this->maxHp += $amount;
        $this->hp += $amount;

        return $this;
    }

    /**
     * Direct override, unlike increaseMaxHp() — for admin corrections only,
     * game logic should use increaseMaxHp().
     */
    public function setMaxHp(int $maxHp): static
    {
        $this->maxHp = $maxHp;

        return $this;
    }

    public function getEnergy(): int
    {
        return $this->energy;
    }

    public function setEnergy(int $energy): static
    {
        $this->energy = max(0, min($energy, $this->maxEnergy));

        return $this;
    }

    public function getMaxEnergy(): int
    {
        return $this->maxEnergy;
    }

    public function setMaxEnergy(int $maxEnergy): static
    {
        $this->maxEnergy = $maxEnergy;

        return $this;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): static
    {
        $this->level = $level;

        return $this;
    }

    public function getXp(): int
    {
        return $this->xp;
    }

    public function addXp(int $amount): static
    {
        $this->xp += $amount;

        return $this;
    }

    /**
     * For admin corrections only — game logic should use addXp().
     */
    public function setXp(int $xp): static
    {
        $this->xp = $xp;

        return $this;
    }

    public function getCoins(): int
    {
        return $this->coins;
    }

    public function addCoins(int $amount): static
    {
        $this->coins += $amount;

        return $this;
    }

    /**
     * For admin corrections only — game logic should use addCoins()/trySpendCoins().
     */
    public function setCoins(int $coins): static
    {
        $this->coins = $coins;

        return $this;
    }

    public function trySpendCoins(int $amount): bool
    {
        if ($this->coins < $amount) {
            return false;
        }

        $this->coins -= $amount;

        return true;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Used by the admin panel's association dropdowns/labels (EasyAdmin
     * needs entities to be stringable to render them as choices).
     */
    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->user->getDisplayName(), $this->characterClass->getName());
    }
}
