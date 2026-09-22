<?php

namespace App\Entity;

use App\Enum\BitFace;
use App\Repository\BitRepository;
use Doctrine\ORM\Mapping as ORM;

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
