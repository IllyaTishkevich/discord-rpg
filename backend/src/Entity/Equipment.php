<?php

namespace App\Entity;

use App\Enum\BitFace;
use App\Enum\EquipmentEffectType;
use App\Repository\EquipmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A shop catalog item. Buying one permanently applies its effect to the
 * character — either a new Bit, or a max HP increase — there is no
 * equip/unequip slot system in this MVP.
 */
#[ORM\Entity(repositoryClass: EquipmentRepository::class)]
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

    #[ORM\Column(nullable: true)]
    private ?int $hpBonus = null;

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

    public function setBitFaces(BitFace $faceA, BitFace $faceB): static
    {
        $this->bitFaceA = $faceA;
        $this->bitFaceB = $faceB;

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
}
