<?php

namespace App\Entity;

use App\Enum\AbilityType;
use App\Enum\ItemEffectType;
use App\Repository\CharacterRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CharacterRepository::class)]
class Character
{
    /**
     * Base inventory grid size before any equipped Bag's bonus — see
     * getInventoryCapacity().
     */
    private const BASE_INVENTORY_CAPACITY = 12;

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

    /**
     * Bits bought individually via the shop (EquipmentService::purchase()),
     * on top of whatever the class provides — unidirectional, Bit has no
     * inverse property. See getAllBits() for the full battle loadout.
     *
     * @var Collection<int, Bit>
     */
    #[ORM\ManyToMany(targetEntity: Bit::class)]
    #[ORM\JoinTable(name: 'character_bit')]
    private Collection $purchasedBits;

    /**
     * Combat abilities granted directly to this character (via admin, or
     * equipment that grants one on purchase — see
     * EquipmentService::purchase()), on top of whatever its class allows.
     * Unidirectional, Ability has no inverse property. See
     * hasAbilityType() for the full availability check.
     *
     * @var Collection<int, Ability>
     */
    #[ORM\ManyToMany(targetEntity: Ability::class)]
    #[ORM\JoinTable(name: 'character_ability')]
    private Collection $abilities;

    /**
     * Looted items (LootService) held in the 12-cell (+bag bonus) inventory
     * grid — inverse side, CharacterInventoryItem owns the relation. See
     * getAllBits()/getAllAbilities()/getInventoryCapacity() for how an
     * equipped one's effect is derived live from this collection, and
     * InventoryService for use/sell/discard.
     *
     * @var Collection<int, CharacterInventoryItem>
     */
    #[ORM\OneToMany(mappedBy: 'character', targetEntity: CharacterInventoryItem::class, orphanRemoval: true)]
    private Collection $inventoryItems;

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
        $this->purchasedBits = new ArrayCollection();
        $this->abilities = new ArrayCollection();
        $this->inventoryItems = new ArrayCollection();
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

    /**
     * @return Collection<int, Bit>
     */
    public function getPurchasedBits(): Collection
    {
        return $this->purchasedBits;
    }

    public function addPurchasedBit(Bit $bit): static
    {
        if (!$this->purchasedBits->contains($bit)) {
            $this->purchasedBits->add($bit);
        }

        return $this;
    }

    public function removePurchasedBit(Bit $bit): static
    {
        $this->purchasedBits->removeElement($bit);

        return $this;
    }

    /**
     * The full set of bits this character throws every round: its class's
     * bits, whatever it bought individually, plus whatever an equipped
     * AddBit inventory item is currently granting.
     *
     * @return Bit[]
     */
    public function getAllBits(): array
    {
        $equippedBits = [];
        foreach ($this->inventoryItems as $inventoryItem) {
            if ($inventoryItem->isEquipped()
                && ItemEffectType::AddBit === $inventoryItem->getItem()->getEffectType()
                && null !== $inventoryItem->getGrantedBit()
            ) {
                $equippedBits[] = $inventoryItem->getGrantedBit();
            }
        }

        return [...$this->characterClass->getStarterBits(), ...$this->purchasedBits, ...$equippedBits];
    }

    /**
     * @return Collection<int, Ability>
     */
    public function getAbilities(): Collection
    {
        return $this->abilities;
    }

    public function addAbility(Ability $ability): static
    {
        if (!$this->abilities->contains($ability)) {
            $this->abilities->add($ability);
        }

        return $this;
    }

    public function removeAbility(Ability $ability): static
    {
        $this->abilities->removeElement($ability);

        return $this;
    }

    /**
     * The full set of abilities this character can choose from in battle:
     * its class's abilities, whatever it was granted individually, plus
     * whatever an equipped AddAbility inventory item is currently granting.
     *
     * @return Ability[]
     */
    public function getAllAbilities(): array
    {
        $equippedAbilities = [];
        foreach ($this->inventoryItems as $inventoryItem) {
            if ($inventoryItem->isEquipped() && ItemEffectType::AddAbility === $inventoryItem->getItem()->getEffectType()) {
                $ability = $inventoryItem->getItem()->getGrantedAbility();
                if (null !== $ability) {
                    $equippedAbilities[] = $ability;
                }
            }
        }

        return [...$this->characterClass->getAbilities(), ...$this->abilities, ...$equippedAbilities];
    }

    public function hasAbilityType(AbilityType $type): bool
    {
        foreach ($this->getAllAbilities() as $ability) {
            if ($ability->getType() === $type) {
                return true;
            }
        }

        return false;
    }

    public function getHp(): int
    {
        return $this->hp;
    }

    public function setHp(int $hp): static
    {
        $this->hp = max(0, min($hp, $this->getEffectiveMaxHp()));

        return $this;
    }

    /**
     * Raw persisted base — for admin editing/corrections and as the input
     * to increaseMaxHp()'s permanent shop-equipment bonus. Game logic and
     * anything player-facing (serializers, battle/tournament simulation)
     * must use getEffectiveMaxHp() instead, which also includes whatever a
     * currently-equipped IncreaseMaxHp item is granting — kept as a
     * separate method (rather than folding the live bonus into this
     * getter) specifically so EasyAdmin's CharacterCrudController, which
     * binds straight to getMaxHp()/setMaxHp(), keeps editing the true base
     * value: if this getter returned the inflated total, an admin who
     * opens the form and saves without changing anything would silently
     * bake the equipped item's temporary bonus into the persisted base.
     */
    public function getMaxHp(): int
    {
        return $this->maxHp;
    }

    /**
     * Base HP cap plus whatever a currently-equipped ItemEffectType::IncreaseMaxHp
     * item is granting (see getInventoryCapacity() for the same live-bonus
     * pattern applied to a Bag's capacity instead of HP). This is what
     * setHp() actually clamps against, and what every player-facing surface
     * (serializers, the bot, tournament simulation) should read — see
     * getMaxHp()'s own docblock for why that one stays raw-only.
     */
    public function getEffectiveMaxHp(): int
    {
        return $this->maxHp + $this->sumEquippedBonus(ItemEffectType::IncreaseMaxHp, static fn (Item $item) => $item->getMaxHpBonus());
    }

    /**
     * Permanently raises the HP cap (e.g. from a shop Equipment purchase —
     * EquipmentService::purchase() — a one-time, non-reversible effect,
     * unlike an equippable Item's IncreaseMaxHp) and heals by the same
     * amount, matching how "+max HP" items conventionally work.
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
        $this->energy = max(0, min($energy, $this->getEffectiveMaxEnergy()));

        return $this;
    }

    /**
     * Raw persisted base — see getMaxHp()'s docblock for why this stays
     * raw-only rather than including the live equipped-item bonus (same
     * EasyAdmin-corruption concern, same fix: getEffectiveMaxEnergy() below).
     */
    public function getMaxEnergy(): int
    {
        return $this->maxEnergy;
    }

    /**
     * Base energy cap plus whatever a currently-equipped
     * ItemEffectType::IncreaseMaxEnergy item is granting — see
     * getEffectiveMaxHp()'s docblock, same pattern.
     */
    public function getEffectiveMaxEnergy(): int
    {
        return $this->maxEnergy + $this->sumEquippedBonus(ItemEffectType::IncreaseMaxEnergy, static fn (Item $item) => $item->getMaxEnergyBonus());
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
     * @return Collection<int, CharacterInventoryItem>
     */
    public function getInventoryItems(): Collection
    {
        return $this->inventoryItems;
    }

    public function addInventoryItem(CharacterInventoryItem $item): static
    {
        if (!$this->inventoryItems->contains($item)) {
            $this->inventoryItems->add($item);
        }

        return $this;
    }

    public function removeInventoryItem(CharacterInventoryItem $item): static
    {
        $this->inventoryItems->removeElement($item);

        return $this;
    }

    /**
     * 12 cells, plus the combined bonus of every currently-equipped
     * IncreaseCapacity item — driven by effect type, not by ItemType::Bag
     * specifically (nothing actually restricts that effect to bags), so
     * this sums rather than returns the first match: two different-typed
     * equipped items (e.g. a bag AND some other slot) could both carry it
     * at once, since only one item *per type* can be equipped, not one
     * overall.
     */
    public function getInventoryCapacity(): int
    {
        return self::BASE_INVENTORY_CAPACITY + $this->sumEquippedBonus(ItemEffectType::IncreaseCapacity, static fn (Item $item) => $item->getCapacityBonus());
    }

    /**
     * Shared "sum this numeric bonus across every currently-equipped item
     * with this exact effect type" pattern behind getInventoryCapacity()/
     * getEffectiveMaxHp()/getEffectiveMaxEnergy() — unlike getAllBits()/
     * getAllAbilities() (which collect entities, not numbers), so it stays
     * a separate helper rather than folding into those.
     */
    private function sumEquippedBonus(ItemEffectType $effectType, callable $amountGetter): int
    {
        $sum = 0;
        foreach ($this->inventoryItems as $inventoryItem) {
            if ($inventoryItem->isEquipped() && $effectType === $inventoryItem->getItem()->getEffectType()) {
                $sum += $amountGetter($inventoryItem->getItem()) ?? 0;
            }
        }

        return $sum;
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
