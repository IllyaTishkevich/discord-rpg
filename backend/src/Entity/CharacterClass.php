<?php

namespace App\Entity;

use App\Repository\CharacterClassRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

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

    #[ORM\OneToMany(mappedBy: 'characterClass', targetEntity: Character::class)]
    private Collection $characters;

    public function __construct(string $code, string $name, int $baseHp, int $baseEnergy)
    {
        $this->code = $code;
        $this->name = $name;
        $this->baseHp = $baseHp;
        $this->baseEnergy = $baseEnergy;
        $this->starterBits = new ArrayCollection();
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

    public function __toString(): string
    {
        return $this->name ?: $this->code;
    }
}
