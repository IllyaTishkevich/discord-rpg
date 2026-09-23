<?php

namespace App\Entity;

use App\Enum\BitFace;
use App\Enum\ItemEffectType;
use App\Enum\ItemType;
use App\Repository\ItemRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * Catalog entity for loot — can be attached to a Monster's drop table
 * (Monster::$dropItem1..5) and, once dropped, becomes a
 * CharacterInventoryItem the player holds until they use/sell/discard it.
 *
 * Unlike Equipment (shop-only: buying it applies its effect once, instantly
 * and permanently), an Item's effect is either:
 *  - equippable (Weapon/Shield/Armor/Bag, ItemType::isEquippable()): active
 *    only while a CharacterInventoryItem row for it has equipped==true (see
 *    Character::getAllBits()/getAllAbilities()/getInventoryCapacity()) —
 *    reversible, since equipping a different item of the same type
 *    auto-swaps it off (InventoryService::useItem()).
 *  - consumable (Potion/Scroll, ItemType::isConsumable()): applied once,
 *    instantly, by InventoryService::useItem(), which then deletes the row.
 *
 * effectType must match type's equippable/consumable split — enforced by
 * validateEffectMatchesType() below. Only the fields relevant to the chosen
 * effectType are meaningful; the rest stay null/default.
 */
#[ORM\Entity(repositoryClass: ItemRepository::class)]
#[Vich\Uploadable]
#[Assert\Callback(callback: 'validateEffectMatchesType')]
class Item
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private int $price = 0;

    #[ORM\Column(enumType: ItemType::class)]
    private ItemType $type;

    #[ORM\Column(enumType: ItemEffectType::class)]
    private ItemEffectType $effectType;

    // --- AddBit fields (same shape as Equipment's bit-grant fields) ---

    #[ORM\Column(enumType: BitFace::class, nullable: true)]
    private ?BitFace $bitFaceA = null;

    #[ORM\Column(enumType: BitFace::class, nullable: true)]
    private ?BitFace $bitFaceB = null;

    #[ORM\Column]
    private bool $bitAdvantageA = false;

    #[ORM\Column]
    private bool $bitAdvantageB = false;

    #[ORM\Column]
    #[Assert\Positive(message: 'Множитель должен быть не меньше 1.')]
    private int $bitMultiplierA = 1;

    #[ORM\Column]
    #[Assert\Positive(message: 'Множитель должен быть не меньше 1.')]
    private int $bitMultiplierB = 1;

    // --- AddAbility field ---

    #[ORM\ManyToOne(targetEntity: Ability::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Ability $grantedAbility = null;

    // --- IncreaseCapacity field (Bag) ---

    #[ORM\Column(nullable: true)]
    private ?int $capacityBonus = null;

    // --- IncreaseMaxHp / IncreaseMaxEnergy fields ---

    #[ORM\Column(nullable: true)]
    private ?int $maxHpBonus = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxEnergyBonus = null;

    // --- Consumable fields (Potion/Scroll) ---

    #[ORM\Column(nullable: true)]
    private ?int $healAmount = null;

    #[ORM\Column(nullable: true)]
    private ?int $energyAmount = null;

    #[ORM\Column(nullable: true)]
    private ?int $xpAmount = null;

    /**
     * Not persisted — Vich reads this on flush to store the file and fill
     * iconName/iconSize, then clears it (see Monster::$iconFile).
     */
    #[Vich\UploadableField(mapping: 'item_icon', fileNameProperty: 'iconName', size: 'iconSize')]
    #[Assert\Image(
        minWidth: 256,
        maxWidth: 256,
        minHeight: 256,
        maxHeight: 256,
        minWidthMessage: 'Иконка должна быть ровно 256x256 пикселей.',
        maxWidthMessage: 'Иконка должна быть ровно 256x256 пикселей.',
        minHeightMessage: 'Иконка должна быть ровно 256x256 пикселей.',
        maxHeightMessage: 'Иконка должна быть ровно 256x256 пикселей.',
    )]
    private ?File $iconFile = null;

    #[ORM\Column(nullable: true)]
    private ?string $iconName = null;

    #[ORM\Column(nullable: true)]
    private ?int $iconSize = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $iconUpdatedAt = null;

    public function __construct(string $name, int $price, ItemType $type, ItemEffectType $effectType)
    {
        $this->name = $name;
        $this->price = $price;
        $this->type = $type;
        $this->effectType = $effectType;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
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

    public function getPrice(): int
    {
        return $this->price;
    }

    public function setPrice(int $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getType(): ItemType
    {
        return $this->type;
    }

    public function setType(ItemType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getEffectType(): ItemEffectType
    {
        return $this->effectType;
    }

    public function setEffectType(ItemEffectType $effectType): static
    {
        $this->effectType = $effectType;

        return $this;
    }

    public function getBitFaceA(): ?BitFace
    {
        return $this->bitFaceA;
    }

    public function setBitFaceA(?BitFace $bitFaceA): static
    {
        $this->bitFaceA = $bitFaceA;

        return $this;
    }

    public function getBitFaceB(): ?BitFace
    {
        return $this->bitFaceB;
    }

    public function setBitFaceB(?BitFace $bitFaceB): static
    {
        $this->bitFaceB = $bitFaceB;

        return $this;
    }

    public function setBitFaces(
        BitFace $faceA,
        BitFace $faceB,
        bool $advantageA = false,
        bool $advantageB = false,
        int $multiplierA = 1,
        int $multiplierB = 1,
    ): static {
        $this->bitFaceA = $faceA;
        $this->bitFaceB = $faceB;
        $this->bitAdvantageA = $advantageA;
        $this->bitAdvantageB = $advantageB;
        $this->bitMultiplierA = $multiplierA;
        $this->bitMultiplierB = $multiplierB;

        return $this;
    }

    public function hasBitAdvantageA(): bool
    {
        return $this->bitAdvantageA;
    }

    public function setBitAdvantageA(bool $bitAdvantageA): static
    {
        $this->bitAdvantageA = $bitAdvantageA;

        return $this;
    }

    public function hasBitAdvantageB(): bool
    {
        return $this->bitAdvantageB;
    }

    public function setBitAdvantageB(bool $bitAdvantageB): static
    {
        $this->bitAdvantageB = $bitAdvantageB;

        return $this;
    }

    public function getBitMultiplierA(): int
    {
        return $this->bitMultiplierA;
    }

    public function setBitMultiplierA(int $bitMultiplierA): static
    {
        $this->bitMultiplierA = $bitMultiplierA;

        return $this;
    }

    public function getBitMultiplierB(): int
    {
        return $this->bitMultiplierB;
    }

    public function setBitMultiplierB(int $bitMultiplierB): static
    {
        $this->bitMultiplierB = $bitMultiplierB;

        return $this;
    }

    public function getGrantedAbility(): ?Ability
    {
        return $this->grantedAbility;
    }

    public function setGrantedAbility(?Ability $grantedAbility): static
    {
        $this->grantedAbility = $grantedAbility;

        return $this;
    }

    public function getCapacityBonus(): ?int
    {
        return $this->capacityBonus;
    }

    public function setCapacityBonus(?int $capacityBonus): static
    {
        $this->capacityBonus = $capacityBonus;

        return $this;
    }

    public function getMaxHpBonus(): ?int
    {
        return $this->maxHpBonus;
    }

    public function setMaxHpBonus(?int $maxHpBonus): static
    {
        $this->maxHpBonus = $maxHpBonus;

        return $this;
    }

    public function getMaxEnergyBonus(): ?int
    {
        return $this->maxEnergyBonus;
    }

    public function setMaxEnergyBonus(?int $maxEnergyBonus): static
    {
        $this->maxEnergyBonus = $maxEnergyBonus;

        return $this;
    }

    public function getHealAmount(): ?int
    {
        return $this->healAmount;
    }

    public function setHealAmount(?int $healAmount): static
    {
        $this->healAmount = $healAmount;

        return $this;
    }

    public function getEnergyAmount(): ?int
    {
        return $this->energyAmount;
    }

    public function setEnergyAmount(?int $energyAmount): static
    {
        $this->energyAmount = $energyAmount;

        return $this;
    }

    public function getXpAmount(): ?int
    {
        return $this->xpAmount;
    }

    public function setXpAmount(?int $xpAmount): static
    {
        $this->xpAmount = $xpAmount;

        return $this;
    }

    public function setIconFile(?File $iconFile = null): static
    {
        $this->iconFile = $iconFile;

        if (null !== $iconFile) {
            $this->iconUpdatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getIconFile(): ?File
    {
        return $this->iconFile;
    }

    public function setIconName(?string $iconName): static
    {
        $this->iconName = $iconName;

        return $this;
    }

    public function getIconName(): ?string
    {
        return $this->iconName;
    }

    public function setIconSize(?int $iconSize): static
    {
        $this->iconSize = $iconSize;

        return $this;
    }

    public function getIconSize(): ?int
    {
        return $this->iconSize;
    }

    public function validateEffectMatchesType(ExecutionContextInterface $context): void
    {
        if ($this->type->isEquippable() !== $this->effectType->isEquippableEffect()) {
            $context->buildViolation('Тип эффекта должен соответствовать типу предмета: оружие/щит/доспех/сумка — только постоянные эффекты (биту/способность/вместимость), зелье/свиток — только мгновенные (hp/энергия/опыт).')
                ->atPath('effectType')
                ->addViolation();
        }
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
