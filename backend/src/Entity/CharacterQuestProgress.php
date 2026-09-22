<?php

namespace App\Entity;

use App\Repository\CharacterQuestProgressRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CharacterQuestProgressRepository::class)]
class CharacterQuestProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Character $character;

    #[ORM\ManyToOne(targetEntity: WeeklyQuest::class)]
    #[ORM\JoinColumn(nullable: false)]
    private WeeklyQuest $quest;

    #[ORM\Column]
    private int $progress = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    public function __construct(Character $character, WeeklyQuest $quest)
    {
        $this->character = $character;
        $this->quest = $quest;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function getQuest(): WeeklyQuest
    {
        return $this->quest;
    }

    public function getProgress(): int
    {
        return $this->progress;
    }

    public function incrementProgress(int $amount = 1): static
    {
        $this->progress = min($this->progress + $amount, $this->quest->getTargetValue());

        return $this;
    }

    public function isComplete(): bool
    {
        return $this->progress >= $this->quest->getTargetValue();
    }

    public function getClaimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function isClaimed(): bool
    {
        return null !== $this->claimedAt;
    }

    public function claim(): static
    {
        $this->claimedAt = new \DateTimeImmutable();

        return $this;
    }
}
