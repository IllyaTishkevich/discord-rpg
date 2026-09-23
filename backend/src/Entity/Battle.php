<?php

namespace App\Entity;

use App\Enum\BattleMode;
use App\Enum\BattleStatus;
use App\Repository\BattleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A battle between a character and either a bot-controlled opponent
 * (PvE/event — opponentName/opponentHp carry the monster's stats) or a
 * second real character (PvP — opponentCharacter is set and its own
 * hp/maxHp are the source of truth, same as `character`'s).
 *
 * See docs/BATTLE_ROOM_DESIGN.md for the full PvP lifecycle: a PvP battle
 * is created in `waiting` status (the /duel invite *is* the Battle row —
 * no separate invite entity) and only becomes `in_progress` once both
 * sides have accepted/readied up.
 */
#[ORM\Entity(repositoryClass: BattleRepository::class)]
class Battle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: BattleMode::class)]
    private BattleMode $mode;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Character $character;

    #[ORM\Column(length: 64)]
    private string $opponentName;

    #[ORM\Column]
    private int $opponentHp;

    #[ORM\Column]
    private int $opponentMaxHp;

    /**
     * Set only when mode = Pvp. When set, the opponent's HP lives on this
     * Character directly (its own hp/maxHp), not in opponentHp/opponentMaxHp
     * above — same as how `character`'s HP always has been.
     */
    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Character $opponentCharacter = null;

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
     * Set for PvE/event battles whose opponent was drawn from the Monster
     * catalog — its bits/abilities are then what throwRound()/the bot's
     * ability choice actually use, instead of BattleService's old hardcoded
     * fallback constants (kept for battles/catalog rows predating this, or
     * an empty catalog). opponentName/opponentHp above are still the source
     * of truth for display and HP tracking, just copied from this monster
     * at battle creation time.
     */
    #[ORM\ManyToOne(targetEntity: Monster::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Monster $opponentMonster = null;

    // PvP-only readiness flags — true by default so PvE/event battles (which
    // never go through the waiting-room flow) are unaffected.
    #[ORM\Column]
    private bool $characterReady = true;

    #[ORM\Column]
    private bool $opponentReady = true;

    #[ORM\Column]
    private bool $opponentAccepted = true;

    /**
     * PvP-only: deadline for whichever side currently owes the next move
     * (lead or respond) in the interactive exchange flow — refreshed every
     * time the "turn" passes to a (possibly different) side. Checked lazily
     * (on the next request that touches this battle) rather than via a
     * worker — see docs/BATTLE_ROOM_DESIGN.md §6 and
     * BattleService::applyPvpMoveTimeoutIfExpired().
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $roundDeadlineAt = null;

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

    /**
     * Mid-round state for the interactive step-by-step exchange flow
     * (ExchangeRoundState::toArray() shape) — non-null exactly while a
     * round's exchanges are still being played out, i.e. whenever there's a
     * pending throw. Used by both PvE/event (bot auto-plays its side) and
     * PvP (both sides are real players, each submitting their own lead/
     * respond via separate requests — see BattleService::submitExchangeMove().
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $pendingExchangeState = null;

    #[ORM\OneToMany(mappedBy: 'battle', targetEntity: BattleRound::class, orphanRemoval: true)]
    #[ORM\OrderBy(['roundNumber' => 'ASC'])]
    private Collection $rounds;

    public function __construct(Character $character, string $opponentName, int $opponentHp, BattleMode $mode = BattleMode::Pve)
    {
        $this->character = $character;
        $this->opponentName = $opponentName;
        $this->opponentHp = $opponentHp;
        $this->opponentMaxHp = $opponentHp;
        $this->mode = $mode;
        $this->status = BattleStatus::InProgress;
        $this->createdAt = new \DateTimeImmutable();
        $this->rounds = new ArrayCollection();
    }

    /**
     * A duel challenge: the Battle row itself is the invite/lobby (status
     * Waiting) — no separate invite entity. See docs/BATTLE_ROOM_DESIGN.md §3.
     */
    public static function createPvp(Character $challenger, Character $opponent): self
    {
        $battle = new self($challenger, '', 0, BattleMode::Pvp);
        $battle->opponentCharacter = $opponent;
        $battle->status = BattleStatus::Waiting;
        $battle->characterReady = false;
        $battle->opponentReady = false;
        $battle->opponentAccepted = false;

        return $battle;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMode(): BattleMode
    {
        return $this->mode;
    }

    public function isPvp(): bool
    {
        return BattleMode::Pvp === $this->mode;
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

    public function getOpponentCharacter(): ?Character
    {
        return $this->opponentCharacter;
    }

    public function isParticipant(Character $character): bool
    {
        return $this->character === $character || $this->opponentCharacter === $character;
    }

    public function getStatus(): BattleStatus
    {
        return $this->status;
    }

    public function setStatus(BattleStatus $status): static
    {
        $this->status = $status;
        if (\in_array($status, [BattleStatus::Won, BattleStatus::Lost, BattleStatus::Abandoned], true)) {
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

    public function getOpponentMonster(): ?Monster
    {
        return $this->opponentMonster;
    }

    public function setOpponentMonster(?Monster $opponentMonster): static
    {
        $this->opponentMonster = $opponentMonster;

        return $this;
    }

    public function isCharacterReady(): bool
    {
        return $this->characterReady;
    }

    public function isOpponentReady(): bool
    {
        return $this->opponentReady;
    }

    public function isOpponentAccepted(): bool
    {
        return $this->opponentAccepted;
    }

    public function acceptAsOpponent(): static
    {
        $this->opponentAccepted = true;

        return $this;
    }

    /**
     * Marks the given side ready. Returns true if BOTH sides are now ready
     * (i.e. the caller should transition the battle to in_progress and
     * throw the first round) — kept as a return value rather than doing it
     * here so BattleService stays the single place that touches the combat
     * engine.
     */
    public function markReady(bool $asOpponentSide): bool
    {
        if ($asOpponentSide) {
            $this->opponentReady = true;
        } else {
            $this->characterReady = true;
        }

        return $this->characterReady && $this->opponentReady && $this->opponentAccepted;
    }

    public function getRoundDeadlineAt(): ?\DateTimeImmutable
    {
        return $this->roundDeadlineAt;
    }

    public function setRoundDeadlineAt(?\DateTimeImmutable $roundDeadlineAt): static
    {
        $this->roundDeadlineAt = $roundDeadlineAt;

        return $this;
    }

    public function isRoundDeadlinePassed(): bool
    {
        return null !== $this->roundDeadlineAt && $this->roundDeadlineAt < new \DateTimeImmutable();
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
     * @return array<string, mixed>|null ExchangeRoundState::toArray() shape
     */
    public function getPendingExchangeState(): ?array
    {
        return $this->pendingExchangeState;
    }

    /**
     * @param array<string, mixed>|null $state
     */
    public function setPendingExchangeState(?array $state): static
    {
        $this->pendingExchangeState = $state;

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
