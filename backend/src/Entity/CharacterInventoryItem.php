<?php

namespace App\Entity;

use App\Repository\CharacterInventoryItemRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One owned item instance — one inventory cell (no stacking: even several
 * copies of the same Item each get their own row, occupying their own cell).
 *
 * `equipped` is the only state that changes day to day. Character::getAllBits()/
 * getAllAbilities()/getInventoryCapacity() read live off this flag rather
 * than mutating Character's own collections/fields — so equipping,
 * re-equipping, or auto-swapping to a different item of the same type
 * (InventoryService::useItem()) never has to create/delete a Bit or touch
 * Character::$abilities; flipping this one flag is enough, and it's always
 * safe to flip back and forth.
 */
#[ORM\Entity(repositoryClass: CharacterInventoryItemRepository::class)]
class CharacterInventoryItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Character::class, inversedBy: 'inventoryItems')]
    #[ORM\JoinColumn(nullable: false)]
    private Character $character;

    #[ORM\ManyToOne(targetEntity: Item::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Item $item;

    #[ORM\Column]
    private bool $equipped = false;

    /**
     * Only set for an ItemEffectType::AddBit item — a concrete Bit row
     * created once, at drop time (LootService::grantItem(), mirroring
     * EquipmentService::purchase()'s Bit creation), and kept here regardless
     * of equipped state. See this class's docblock for why equip/unequip
     * never touches it.
     */
    #[ORM\ManyToOne(targetEntity: Bit::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Bit $grantedBit = null;

    #[ORM\Column]
    private \DateTimeImmutable $acquiredAt;

    public function __construct(Character $character, Item $item)
    {
        $this->character = $character;
        $this->item = $item;
        $this->acquiredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function getItem(): Item
    {
        return $this->item;
    }

    public function isEquipped(): bool
    {
        return $this->equipped;
    }

    public function setEquipped(bool $equipped): static
    {
        $this->equipped = $equipped;

        return $this;
    }

    public function getGrantedBit(): ?Bit
    {
        return $this->grantedBit;
    }

    public function setGrantedBit(?Bit $grantedBit): static
    {
        $this->grantedBit = $grantedBit;

        return $this;
    }

    public function getAcquiredAt(): \DateTimeImmutable
    {
        return $this->acquiredAt;
    }
}
