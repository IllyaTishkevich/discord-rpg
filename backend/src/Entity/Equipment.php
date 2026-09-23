<?php

namespace App\Entity;

use App\Enum\BitFace;
use App\Enum\EquipmentEffectType;
use App\Repository\EquipmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * A shop catalog item. Buying one permanently applies its effect to the
 * character — either a new Bit, or a max HP increase — there is no
 * equip/unequip slot system in this MVP.
 */
#[ORM\Entity(repositoryClass: EquipmentRepository::class)]
#[Vich\Uploadable]
class Equipment
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
    private int $price;

    #[ORM\Column(enumType: EquipmentEffectType::class)]
    private EquipmentEffectType $effectType;

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

    #[ORM\Column(nullable: true)]
    private ?int $hpBonus = null;

    /**
     * Optional, independent of $effectType — buying this item also grants
     * the character this ability permanently (EquipmentService::purchase()),
     * on top of whatever its HP/bit effect does.
     */
    #[ORM\ManyToOne(targetEntity: Ability::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Ability $grantedAbility = null;

    /**
     * Not persisted — Vich reads this on flush to store the file and fill
     * iconName/iconSize, then clears it (see Monster::$iconFile).
     */
    #[Vich\UploadableField(mapping: 'equipment_icon', fileNameProperty: 'iconName', size: 'iconSize')]
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

    public function __construct(string $code, string $name, int $price, EquipmentEffectType $effectType)
    {
        $this->code = $code;
        $this->name = $name;
        $this->price = $price;
        $this->effectType = $effectType;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
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

    public function getEffectType(): EquipmentEffectType
    {
        return $this->effectType;
    }

    public function setEffectType(EquipmentEffectType $effectType): static
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

    public function getHpBonus(): ?int
    {
        return $this->hpBonus;
    }

    public function setHpBonus(?int $hpBonus): static
    {
        $this->hpBonus = $hpBonus;

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
}
