<?php

namespace App\Entity;

use App\Enum\BitFace;
use App\Repository\BitRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A two-sided coin. Thrown during battle rounds to randomly reveal one of
 * its two faces.
 *
 * Either owned by a single `character` (a concrete bit in that character's
 * loadout — `character` set, `characterClasses` empty), or a reusable
 * **template** assignable to any number of character classes as their
 * starter loadout (`character` null, `characterClasses` non-empty) — never
 * both at once. Character creation copies each of the class's template
 * bits into a fresh owned Bit for the new character (see
 * CharacterController::create()); editing a template afterwards doesn't
 * retroactively change already-created characters.
 */
#[ORM\Entity(repositoryClass: BitRepository::class)]
class Bit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Character $character;

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
     * Classes that use this bit as part of their starter loadout — only
     * meaningful for template bits (character === null). Owning side of
     * the relation; CharacterClass::$starterBits is the inverse side.
     *
     * @var Collection<int, CharacterClass>
     */
    #[ORM\ManyToMany(targetEntity: CharacterClass::class, inversedBy: 'starterBits')]
    #[ORM\JoinTable(name: 'character_class_bit')]
    private Collection $characterClasses;

    public function __construct(?Character $character, BitFace $faceA, BitFace $faceB, bool $advantageA = false, bool $advantageB = false)
    {
        $this->character = $character;
        $this->faceA = $faceA;
        $this->faceB = $faceB;
        $this->advantageA = $advantageA;
        $this->advantageB = $advantageB;
        $this->characterClasses = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacter(): ?Character
    {
        return $this->character;
    }

    public function setCharacter(?Character $character): static
    {
        $this->character = $character;

        return $this;
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
     * @return Collection<int, CharacterClass>
     */
    public function getCharacterClasses(): Collection
    {
        return $this->characterClasses;
    }

    public function addCharacterClass(CharacterClass $characterClass): static
    {
        if (!$this->characterClasses->contains($characterClass)) {
            $this->characterClasses->add($characterClass);
            $characterClass->addStarterBit($this);
        }

        return $this;
    }

    public function removeCharacterClass(CharacterClass $characterClass): static
    {
        if ($this->characterClasses->removeElement($characterClass)) {
            $characterClass->removeStarterBit($this);
        }

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
