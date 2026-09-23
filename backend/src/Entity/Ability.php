<?php

namespace App\Entity;

use App\Enum\AbilityType;
use App\Repository\AbilityRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * Catalog row for one of the 6 fixed combat abilities (App\Enum\AbilityType)
 * — exists so classes, characters and equipment can each reference "which
 * abilities can this side use", the same way Bit works for the coin
 * definitions themselves. There's normally exactly one Ability row per
 * AbilityType (enforced by a unique constraint), seeded once
 * (SeedAbilitiesCommand) and never really needing more.
 *
 * Availability during a real battle: Character::hasAbilityType() checks the
 * union of the character's class's abilities and its own directly-granted
 * ones (see CharacterClass::$abilities, Character::$abilities) — see
 * docs/BATTLE_RULES.md §3.1.
 */
#[ORM\Entity(repositoryClass: AbilityRepository::class)]
#[Vich\Uploadable]
class Ability
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: AbilityType::class, unique: true)]
    private AbilityType $type;

    /**
     * Player-facing name — what the Activity's ability picker actually
     * displays (ArenaScreen.tsx, via GET /api/abilities), unlike
     * $description below. Defaults to AbilityType::label() at construction
     * so every existing/seeded row shows something sensible immediately;
     * admins can rename it from there without touching code.
     */
    #[ORM\Column(length: 64)]
    private string $label;

    /**
     * Admin-facing explanation of what the ability actually does — not read
     * by any battle logic, purely documentation for whoever is managing
     * class/character/equipment grants in the admin panel.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Not persisted — Vich reads this on flush to store the file and fill
     * iconName/iconSize, then clears it (see Monster::$iconFile).
     */
    #[Vich\UploadableField(mapping: 'ability_icon', fileNameProperty: 'iconName', size: 'iconSize')]
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

    public function __construct(AbilityType $type)
    {
        $this->type = $type;
        $this->label = $type->label();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): AbilityType
    {
        return $this->type;
    }

    public function setType(AbilityType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

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

    public function __toString(): string
    {
        return $this->label;
    }
}
