<?php

namespace App\Entity;

use App\Enum\BattleStatus;
use App\Repository\BattleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A PvE battle between a character and a bot-controlled opponent.
 * PvP battles are introduced in a later sprint and will reuse this entity
 * with an opponentCharacter set instead of an opponent name/HP pair.
 */
#[ORM\Entity(repositoryClass: BattleRepository::class)]
class Battle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Character $character;

    #[ORM\Column(length: 64)]
    private string $opponentName;

    #[ORM\Column]
    private int $opponentHp;

    #[ORM\Column]
    private int $opponentMaxHp;

    #[ORM\Column(enumType: BattleStatus::class)]
    private BattleStatus $status;

    #[ORM\Column]
    private int $roundNumber = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /**
     * Set when this battle was fought against a server-wide event's monster
     * rather than the regular training bot — doesn't cost energy, and pays
     * out bigger rewards (see BattleService::resolveOutcome).
     */
    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Event $event = null;

    /**
     * Both sides' bits thrown for the round currently awaiting resolution —
     * set by "throw", consumed and cleared by "resolve". Storing this
     * server-side (rather than trusting the client to echo it back) is what
     * lets the player see the board and choose action targets before
     * damage is computed, without letting them fake their own throw.
     *
     * @var array{faceA: string, faceB: string, thrownFace: string}[]|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $pendingPlayerThrows = null;

    /** @var array{faceA: string, faceB: string, thrownFace: string}[]|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $pendingOpponentThrows = null;

    #[ORM\OneToMany(mappedBy: 'battle', targetEntity: BattleRound::class, orphanRemoval: true)]
    #[ORM\OrderBy(['roundNumber' => 'ASC'])]
    private Collection $rounds;

    public function __construct(Character $character, string $opponentName, int $opponentHp)
    {
        $this->character = $character;
        $this->opponentName = $opponentName;
        $this->opponentHp = $opponentHp;
        $this->opponentMaxHp = $opponentHp;
        $this->status = BattleStatus::InProgress;
        $this->createdAt = new \DateTimeImmutable();
        $this->rounds = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function getOpponentName(): string
    {
        return $this->opponentName;
    }

    public function getOpponentHp(): int
    {
        return $this->opponentHp;
    }

    public function setOpponentHp(int $hp): static
    {
        $this->opponentHp = max(0, min($hp, $this->opponentMaxHp));

        return $this;
    }

    public function getOpponentMaxHp(): int
    {
        return $this->opponentMaxHp;
    }

    public function getStatus(): BattleStatus
    {
        return $this->status;
    }

    public function setStatus(BattleStatus $status): static
    {
        $this->status = $status;
        if (BattleStatus::InProgress !== $status) {
            $this->finishedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getRoundNumber(): int
    {
        return $this->roundNumber;
    }

    public function incrementRoundNumber(): static
    {
        ++$this->roundNumber;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): static
    {
        $this->event = $event;

        return $this;
    }

    public function hasPendingThrow(): bool
    {
        return null !== $this->pendingPlayerThrows;
    }

    public function getPendingPlayerThrows(): ?array
    {
        return $this->pendingPlayerThrows;
    }

    public function getPendingOpponentThrows(): ?array
    {
        return $this->pendingOpponentThrows;
    }

    public function setPendingThrows(?array $playerThrows, ?array $opponentThrows): static
    {
        $this->pendingPlayerThrows = $playerThrows;
        $this->pendingOpponentThrows = $opponentThrows;

        return $this;
    }

    /**
     * @return Collection<int, BattleRound>
     */
    public function getRounds(): Collection
    {
        return $this->rounds;
    }
}
