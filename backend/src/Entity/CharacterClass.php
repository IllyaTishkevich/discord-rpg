<?php

namespace App\Entity;

use App\Repository\CharacterClassRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * Reference entity describing a playable class: base stats and the starter
 * set of bits every new character of this class receives.
 *
 * `starterBits` is a many-to-many association to `Bit` rows — a
 * unidirectional relation (Bit itself holds no reference back, see its
 * docblock). Every character of this class throws exactly these bits each
 * round, plus whatever it bought individually (Character::$purchasedBits) —
 * see Character::getAllBits(). Nothing is copied per-character: editing
 * this list changes every current and future character of the class
 * immediately.
 */
#[ORM\Entity(repositoryClass: CharacterClassRepository::class)]
#[Vich\Uploadable]
class CharacterClass
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
    private int $baseHp;

    #[ORM\Column]
    private int $baseEnergy;

    /**
     * Owning side — unidirectional, Bit has no inverse property.
     *
     * @var Collection<int, Bit>
     */
    #[ORM\ManyToMany(targetEntity: Bit::class)]
    #[ORM\JoinTable(name: 'character_class_bit')]
    private Collection $starterBits;

    /**
     * Combat abilities every character of this class can choose from (on
     * top of whatever it was granted individually — see
     * Character::$abilities and Character::hasAbilityType()). Owning
     * side — unidirectional, Ability has no inverse property.
     *
     * @var Collection<int, Ability>
     */
    #[ORM\ManyToMany(targetEntity: Ability::class)]
    #[ORM\JoinTable(name: 'character_class_ability')]
    private Collection $abilities;

    #[ORM\OneToMany(mappedBy: 'characterClass', targetEntity: Character::class)]
    private Collection $characters;

    /**
     * Not persisted — Vich reads this on flush to store the file and fill
     * iconName/iconSize, then clears it (see Monster::$iconFile).
     */
    #[Vich\UploadableField(mapping: 'character_class_icon', fileNameProperty: 'iconName', size: 'iconSize')]
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

    /**
     * A decorative frame overlaid on top of a character's own avatar (or a
     * PvP opponent's) wherever it's shown — see CombatantBar.tsx on the
     * frontend, which layers it directly on top of avatarUrl. Optional
     * (null means no frame — the plain avatar shows as-is); PNG-only since
     * an overlay that can't be transparent isn't useful as a frame. Not
     * persisted itself — Vich reads this on flush to store the file and
     * fill frameName/frameSize, then clears it (see $iconFile above).
     */
    #[Vich\UploadableField(mapping: 'character_class_frame', fileNameProperty: 'frameName', size: 'frameSize')]
    #[Assert\Image(
        mimeTypes: ['image/png'],
        mimeTypesMessage: 'Рамка должна быть PNG-изображением.',
    )]
    private ?File $frameFile = null;

    #[ORM\Column(nullable: true)]
    private ?string $frameName = null;

    #[ORM\Column(nullable: true)]
    private ?int $frameSize = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $frameUpdatedAt = null;

    public function __construct(string $code, string $name, int $baseHp, int $baseEnergy)
    {
        $this->code = $code;
        $this->name = $name;
        $this->baseHp = $baseHp;
        $this->baseEnergy = $baseEnergy;
        $this->starterBits = new ArrayCollection();
        $this->abilities = new ArrayCollection();
        $this->characters = new ArrayCollection();
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

    public function getBaseHp(): int
    {
        return $this->baseHp;
    }

    public function setBaseHp(int $baseHp): static
    {
        $this->baseHp = $baseHp;

        return $this;
    }

    public function getBaseEnergy(): int
    {
        return $this->baseEnergy;
    }

    public function setBaseEnergy(int $baseEnergy): static
    {
        $this->baseEnergy = $baseEnergy;

        return $this;
    }

    /**
     * @return Collection<int, Bit>
     */
    public function getStarterBits(): Collection
    {
        return $this->starterBits;
    }

    public function addStarterBit(Bit $bit): static
    {
        if (!$this->starterBits->contains($bit)) {
            $this->starterBits->add($bit);
        }

        return $this;
    }

    public function removeStarterBit(Bit $bit): static
    {
        $this->starterBits->removeElement($bit);

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

    /**
     * Read-only, pre-formatted for the admin panel — EasyAdmin's TextField
     * requires a stringable property value, and it checks the raw value's
     * type before any formatValue() callback runs, so a plain collection
     * (even with formatValue configured) throws.
     */
    public function getStarterBitsSummary(): string
    {
        return implode(', ', array_map(
            static fn (Bit $bit) => sprintf('%s/%s', $bit->getFaceA()->value, $bit->getFaceB()->value),
            $this->starterBits->toArray(),
        ));
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

    public function setFrameFile(?File $frameFile = null): static
    {
        $this->frameFile = $frameFile;

        if (null !== $frameFile) {
            $this->frameUpdatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getFrameFile(): ?File
    {
        return $this->frameFile;
    }

    public function setFrameName(?string $frameName): static
    {
        $this->frameName = $frameName;

        return $this;
    }

    public function getFrameName(): ?string
    {
        return $this->frameName;
    }

    public function setFrameSize(?int $frameSize): static
    {
        $this->frameSize = $frameSize;

        return $this;
    }

    public function getFrameSize(): ?int
    {
        return $this->frameSize;
    }

    public function __toString(): string
    {
        return $this->name ?: $this->code;
    }
}
