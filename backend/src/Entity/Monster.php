<?php

namespace App\Entity;

use App\Repository\MonsterRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * Catalog entity for a PvE opponent ("bot"). Not yet wired into
 * BattleService::startPveBattle(), which still uses its own hardcoded
 * name/HP/bits — this is the admin-facing catalog only, matching how
 * CharacterClass/Ability were introduced before battle logic read from them.
 *
 * `bits`/`abilities` mirror CharacterClass's unidirectional M2M pattern (see
 * its docblock) — Bit/Ability hold no reference back.
 */
#[ORM\Entity(repositoryClass: MonsterRepository::class)]
#[Vich\Uploadable]
class Monster
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
    private int $level;

    #[ORM\Column]
    private int $maxHp;

    /**
     * Owning side — unidirectional, Bit has no inverse property.
     *
     * @var Collection<int, Bit>
     */
    #[ORM\ManyToMany(targetEntity: Bit::class)]
    #[ORM\JoinTable(name: 'monster_bit')]
    private Collection $bits;

    /**
     * Owning side — unidirectional, Ability has no inverse property.
     *
     * @var Collection<int, Ability>
     */
    #[ORM\ManyToMany(targetEntity: Ability::class)]
    #[ORM\JoinTable(name: 'monster_ability')]
    private Collection $abilities;

    /**
     * Up to 5 possible drops, each with its own independent chance (percent,
     * 1-100) rolled separately by LootService when this monster is defeated
     * in PvE — see getDrops(). A slot only counts if both its item and
     * chance are set.
     */
    #[ORM\ManyToOne(targetEntity: Item::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Item $dropItem1 = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 100, notInRangeMessage: 'Шанс дропа — от 1 до 100 (%).')]
    private ?int $dropChance1 = null;

    #[ORM\ManyToOne(targetEntity: Item::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Item $dropItem2 = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 100, notInRangeMessage: 'Шанс дропа — от 1 до 100 (%).')]
    private ?int $dropChance2 = null;

    #[ORM\ManyToOne(targetEntity: Item::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Item $dropItem3 = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 100, notInRangeMessage: 'Шанс дропа — от 1 до 100 (%).')]
    private ?int $dropChance3 = null;

    #[ORM\ManyToOne(targetEntity: Item::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Item $dropItem4 = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 100, notInRangeMessage: 'Шанс дропа — от 1 до 100 (%).')]
    private ?int $dropChance4 = null;

    #[ORM\ManyToOne(targetEntity: Item::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Item $dropItem5 = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 100, notInRangeMessage: 'Шанс дропа — от 1 до 100 (%).')]
    private ?int $dropChance5 = null;

    /**
     * Not persisted — Vich reads this on flush to store the file and fill
     * iconName/iconUpdatedAt, then clears it.
     */
    #[Vich\UploadableField(mapping: 'monster_icon', fileNameProperty: 'iconName', size: 'iconSize')]
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

    /**
     * Required by Vich to detect a changed file on entities that are
     * otherwise unchanged (it compares this against Doctrine's UoW).
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $iconUpdatedAt = null;

    public function __construct(string $name, int $level, int $maxHp)
    {
        $this->name = $name;
        $this->level = $level;
        $this->maxHp = $maxHp;
        $this->bits = new ArrayCollection();
        $this->abilities = new ArrayCollection();
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

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): static
    {
        $this->level = $level;

        return $this;
    }

    public function getMaxHp(): int
    {
        return $this->maxHp;
    }

    public function setMaxHp(int $maxHp): static
    {
        $this->maxHp = $maxHp;

        return $this;
    }

    /**
     * @return Collection<int, Bit>
     */
    public function getBits(): Collection
    {
        return $this->bits;
    }

    public function addBit(Bit $bit): static
    {
        if (!$this->bits->contains($bit)) {
            $this->bits->add($bit);
        }

        return $this;
    }

    public function removeBit(Bit $bit): static
    {
        $this->bits->removeElement($bit);

        return $this;
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

    public function setIconFile(?File $iconFile = null): static
    {
        $this->iconFile = $iconFile;

        if (null !== $iconFile) {
            // Vich only persists iconName/iconSize once it sees this change.
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

    public function getDropItem1(): ?Item
    {
        return $this->dropItem1;
    }

    public function setDropItem1(?Item $dropItem1): static
    {
        $this->dropItem1 = $dropItem1;

        return $this;
    }

    public function getDropChance1(): ?int
    {
        return $this->dropChance1;
    }

    public function setDropChance1(?int $dropChance1): static
    {
        $this->dropChance1 = $dropChance1;

        return $this;
    }

    public function getDropItem2(): ?Item
    {
        return $this->dropItem2;
    }

    public function setDropItem2(?Item $dropItem2): static
    {
        $this->dropItem2 = $dropItem2;

        return $this;
    }

    public function getDropChance2(): ?int
    {
        return $this->dropChance2;
    }

    public function setDropChance2(?int $dropChance2): static
    {
        $this->dropChance2 = $dropChance2;

        return $this;
    }

    public function getDropItem3(): ?Item
    {
        return $this->dropItem3;
    }

    public function setDropItem3(?Item $dropItem3): static
    {
        $this->dropItem3 = $dropItem3;

        return $this;
    }

    public function getDropChance3(): ?int
    {
        return $this->dropChance3;
    }

    public function setDropChance3(?int $dropChance3): static
    {
        $this->dropChance3 = $dropChance3;

        return $this;
    }

    public function getDropItem4(): ?Item
    {
        return $this->dropItem4;
    }

    public function setDropItem4(?Item $dropItem4): static
    {
        $this->dropItem4 = $dropItem4;

        return $this;
    }

    public function getDropChance4(): ?int
    {
        return $this->dropChance4;
    }

    public function setDropChance4(?int $dropChance4): static
    {
        $this->dropChance4 = $dropChance4;

        return $this;
    }

    public function getDropItem5(): ?Item
    {
        return $this->dropItem5;
    }

    public function setDropItem5(?Item $dropItem5): static
    {
        $this->dropItem5 = $dropItem5;

        return $this;
    }

    public function getDropChance5(): ?int
    {
        return $this->dropChance5;
    }

    public function setDropChance5(?int $dropChance5): static
    {
        $this->dropChance5 = $dropChance5;

        return $this;
    }

    /**
     * Non-null (item, chance) slot pairs only — for LootService to iterate.
     *
     * @return array<array{item: Item, chance: int}>
     */
    public function getDrops(): array
    {
        $drops = [];
        foreach ([
            [$this->dropItem1, $this->dropChance1],
            [$this->dropItem2, $this->dropChance2],
            [$this->dropItem3, $this->dropChance3],
            [$this->dropItem4, $this->dropChance4],
            [$this->dropItem5, $this->dropChance5],
        ] as [$item, $chance]) {
            if (null !== $item && null !== $chance) {
                $drops[] = ['item' => $item, 'chance' => $chance];
            }
        }

        return $drops;
    }

    /**
     * Read-only, pre-formatted for the admin panel — see
     * CharacterClass::getStarterBitsSummary() for why.
     */
    public function getBitsSummary(): string
    {
        return implode(', ', array_map(
            static fn (Bit $bit) => sprintf('%s/%s', $bit->getFaceA()->value, $bit->getFaceB()->value),
            $this->bits->toArray(),
        ));
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
