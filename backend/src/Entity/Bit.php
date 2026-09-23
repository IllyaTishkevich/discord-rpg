<?php

namespace App\Entity;

use App\Enum\BitFace;
use App\Repository\BitRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * A two-sided coin definition. Thrown during battle rounds to randomly
 * reveal one of its two faces.
 *
 * Standalone — a Bit holds no reference to whoever uses it. Ownership goes
 * the other way: CharacterClass::$starterBits assigns bits to a class (its
 * base loadout, shared by every character of that class), and
 * Character::$purchasedBits assigns bits bought individually via the shop.
 * A character's full battle loadout is the union of both — see
 * Character::getAllBits().
 */
#[ORM\Entity(repositoryClass: BitRepository::class)]
#[Vich\Uploadable]
class Bit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: BitFace::class)]
    private BitFace $faceA;

    #[ORM\Column(enumType: BitFace::class)]
    private BitFace $faceB;

    /**
     * "Преимущество" (advantage) — independent of face type: a face can be
     * e.g. "attack" with or without advantage. Whichever side rolls more
     * advantage faces this round leads the exchange sequence (see
     * docs/COMBAT_V2_DESIGN.md §2).
     */
    #[ORM\Column]
    private bool $advantageA;

    #[ORM\Column]
    private bool $advantageB;

    /**
     * Not persisted — Vich reads this on flush to store the file and fill
     * iconAName/iconASize, then clears it (see Monster::$iconFile).
     */
    #[Vich\UploadableField(mapping: 'bit_icon', fileNameProperty: 'iconAName', size: 'iconASize')]
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
    private ?File $iconAFile = null;

    #[ORM\Column(nullable: true)]
    private ?string $iconAName = null;

    #[ORM\Column(nullable: true)]
    private ?int $iconASize = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $iconAUpdatedAt = null;

    #[Vich\UploadableField(mapping: 'bit_icon', fileNameProperty: 'iconBName', size: 'iconBSize')]
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
    private ?File $iconBFile = null;

    #[ORM\Column(nullable: true)]
    private ?string $iconBName = null;

    #[ORM\Column(nullable: true)]
    private ?int $iconBSize = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $iconBUpdatedAt = null;

    public function __construct(BitFace $faceA, BitFace $faceB, bool $advantageA = false, bool $advantageB = false)
    {
        $this->faceA = $faceA;
        $this->faceB = $faceB;
        $this->advantageA = $advantageA;
        $this->advantageB = $advantageB;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFaceA(): BitFace
    {
        return $this->faceA;
    }

    public function setFaceA(BitFace $faceA): static
    {
        $this->faceA = $faceA;

        return $this;
    }

    public function getFaceB(): BitFace
    {
        return $this->faceB;
    }

    public function setFaceB(BitFace $faceB): static
    {
        $this->faceB = $faceB;

        return $this;
    }

    public function hasAdvantageA(): bool
    {
        return $this->advantageA;
    }

    public function setAdvantageA(bool $advantageA): static
    {
        $this->advantageA = $advantageA;

        return $this;
    }

    public function hasAdvantageB(): bool
    {
        return $this->advantageB;
    }

    public function setAdvantageB(bool $advantageB): static
    {
        $this->advantageB = $advantageB;

        return $this;
    }

    public function setIconAFile(?File $iconAFile = null): static
    {
        $this->iconAFile = $iconAFile;

        if (null !== $iconAFile) {
            $this->iconAUpdatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getIconAFile(): ?File
    {
        return $this->iconAFile;
    }

    public function setIconAName(?string $iconAName): static
    {
        $this->iconAName = $iconAName;

        return $this;
    }

    public function getIconAName(): ?string
    {
        return $this->iconAName;
    }

    public function setIconASize(?int $iconASize): static
    {
        $this->iconASize = $iconASize;

        return $this;
    }

    public function getIconASize(): ?int
    {
        return $this->iconASize;
    }

    public function setIconBFile(?File $iconBFile = null): static
    {
        $this->iconBFile = $iconBFile;

        if (null !== $iconBFile) {
            $this->iconBUpdatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getIconBFile(): ?File
    {
        return $this->iconBFile;
    }

    public function setIconBName(?string $iconBName): static
    {
        $this->iconBName = $iconBName;

        return $this;
    }

    public function getIconBName(): ?string
    {
        return $this->iconBName;
    }

    public function setIconBSize(?int $iconBSize): static
    {
        $this->iconBSize = $iconBSize;

        return $this;
    }

    public function getIconBSize(): ?int
    {
        return $this->iconBSize;
    }

    /**
     * Returns the face opposite to the one given — used to resolve the
     * "action" effect that flips an already-thrown bit.
     */
    public function otherFace(BitFace $face): BitFace
    {
        return $face === $this->faceA ? $this->faceB : $this->faceA;
    }

    public function throwRandomFace(): BitFace
    {
        return random_int(0, 1) === 0 ? $this->faceA : $this->faceB;
    }

    public function __toString(): string
    {
        return sprintf('#%d %s/%s', $this->id ?? 0, $this->faceA->value, $this->faceB->value);
    }
}
