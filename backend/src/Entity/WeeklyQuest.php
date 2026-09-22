<?php

namespace App\Entity;

use App\Repository\WeeklyQuestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One quest per calendar week (Monday start). Only "win_battles" exists for
 * now — see docs/ROADMAP.md's Game Design backlog for more quest types.
 */
#[ORM\Entity(repositoryClass: WeeklyQuestRepository::class)]
class WeeklyQuest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'date_immutable', unique: true)]
    private \DateTimeImmutable $weekStart;

    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column]
    private int $targetValue;

    #[ORM\Column]
    private int $rewardXp;

    #[ORM\Column]
    private int $rewardCoins;

    public function __construct(\DateTimeImmutable $weekStart, string $type, int $targetValue, int $rewardXp, int $rewardCoins)
    {
        $this->weekStart = $weekStart;
        $this->type = $type;
        $this->targetValue = $targetValue;
        $this->rewardXp = $rewardXp;
        $this->rewardCoins = $rewardCoins;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWeekStart(): \DateTimeImmutable
    {
        return $this->weekStart;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTargetValue(): int
    {
        return $this->targetValue;
    }

    public function getRewardXp(): int
    {
        return $this->rewardXp;
    }

    public function getRewardCoins(): int
    {
        return $this->rewardCoins;
    }
}
