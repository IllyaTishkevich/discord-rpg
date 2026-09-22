<?php

namespace App\Entity;

use App\Enum\BitFace;
use App\Repository\BitRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A two-sided coin owned by a character. Thrown during battle rounds to
 * randomly reveal one of its two faces.
 */
#[ORM\Entity(repositoryClass: BitRepository::class)]
class Bit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Character $character;

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

    public function __construct(Character $character, BitFace $faceA, BitFace $faceB, bool $advantageA = false, bool $advantageB = false)
    {
        $this->character = $character;
        $this->faceA = $faceA;
        $this->faceB = $faceB;
        $this->advantageA = $advantageA;
        $this->advantageB = $advantageB;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacter(): Character
    {
        return $this->character;
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

    public function setCharacter(Character $character): static
    {
        $this->character = $character;

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
}
