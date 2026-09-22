<?php

namespace App\Entity;

use App\Enum\EventStatus;
use App\Repository\EventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A periodic server-wide event (currently only "monster_attack"): while
 * active, any player can fight the event's monster via /events/active/battle
 * — reusing the same Battle/BattleRound machinery as a regular PvE fight,
 * just without spending energy and with bigger rewards.
 */
#[ORM\Entity(repositoryClass: EventRepository::class)]
class Event
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column(length: 64)]
    private string $monsterName;

    #[ORM\Column]
    private int $monsterHp;

    #[ORM\Column(enumType: EventStatus::class)]
    private EventStatus $status;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column]
    private \DateTimeImmutable $endsAt;

    public function __construct(string $type, string $monsterName, int $monsterHp, \DateInterval $duration)
    {
        $this->type = $type;
        $this->monsterName = $monsterName;
        $this->monsterHp = $monsterHp;
        $this->status = EventStatus::Active;
        $this->startedAt = new \DateTimeImmutable();
        $this->endsAt = $this->startedAt->add($duration);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getMonsterName(): string
    {
        return $this->monsterName;
    }

    public function getMonsterHp(): int
    {
        return $this->monsterHp;
    }

    public function getStatus(): EventStatus
    {
        return $this->status;
    }

    public function end(): static
    {
        $this->status = EventStatus::Ended;

        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function isActiveNow(): bool
    {
        return EventStatus::Active === $this->status && $this->endsAt > new \DateTimeImmutable();
    }
}
