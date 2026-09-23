<?php

namespace App\Entity;

use App\Repository\BattleRoundRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Log entry for a single resolved round: the final (post-action) face lists
 * for both sides, the resulting damage, and the step-by-step exchange log
 * (combat v2, docs/COMBAT_V2_DESIGN.md) — every mode including PvP produces
 * this now, whether played out incrementally (the interactive Arena) or all
 * at once (BattleService::resolveRound(), bot text commands) — so the
 * Activity can show the player what actually happened instead of just the
 * final tally.
 */
#[ORM\Entity(repositoryClass: BattleRoundRepository::class)]
class BattleRound
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Battle::class, inversedBy: 'rounds')]
    #[ORM\JoinColumn(nullable: false)]
    private Battle $battle;

    #[ORM\Column]
    private int $roundNumber;

    /** @var string[] */
    #[ORM\Column(type: 'json')]
    private array $playerFaces;

    /** @var string[] */
    #[ORM\Column(type: 'json')]
    private array $opponentFaces;

    #[ORM\Column]
    private int $damageToOpponent;

    #[ORM\Column]
    private int $damageToPlayer;

    /**
     * @var array{leaderIsPlayer: bool, leaderFace: string, leaderCount: int, responderFace: ?string, responderCount: int, damageToPlayer: int, damageToOpponent: int}[]
     */
    #[ORM\Column(type: 'json')]
    private array $exchanges;

    /**
     * Loot rolled this round (LootService::rollDrops(), only ever non-empty
     * on the round that actually finishes off a PvE monster) — a snapshot
     * taken at drop time, not a live relation to Item, since the dropped
     * Item's own name/icon could change later and this should keep showing
     * what was actually received. Unlike XP/coins (BattleSerializer::rewardsFor(),
     * recomputed from fixed constants whenever asked), item drops are random
     * and can't be recomputed after the fact, so they have to be captured
     * here instead.
     *
     * @var array{name: string, iconName: ?string}[]
     */
    #[ORM\Column(type: 'json')]
    private array $droppedItems;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param string[]                                                                                                                                                 $playerFaces
     * @param string[]                                                                                                                                                 $opponentFaces
     * @param array{leaderIsPlayer: bool, leaderFace: string, leaderCount: int, responderFace: ?string, responderCount: int, damageToPlayer: int, damageToOpponent: int}[] $exchanges
     * @param array{name: string, iconName: ?string}[]                                                                                                                 $droppedItems
     */
    public function __construct(
        Battle $battle,
        int $roundNumber,
        array $playerFaces,
        array $opponentFaces,
        int $damageToOpponent,
        int $damageToPlayer,
        array $exchanges = [],
        array $droppedItems = [],
    ) {
        $this->battle = $battle;
        $this->roundNumber = $roundNumber;
        $this->playerFaces = $playerFaces;
        $this->opponentFaces = $opponentFaces;
        $this->damageToOpponent = $damageToOpponent;
        $this->damageToPlayer = $damageToPlayer;
        $this->exchanges = $exchanges;
        $this->droppedItems = $droppedItems;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBattle(): Battle
    {
        return $this->battle;
    }

    public function getRoundNumber(): int
    {
        return $this->roundNumber;
    }

    public function getPlayerFaces(): array
    {
        return $this->playerFaces;
    }

    public function getOpponentFaces(): array
    {
        return $this->opponentFaces;
    }

    public function getDamageToOpponent(): int
    {
        return $this->damageToOpponent;
    }

    public function getDamageToPlayer(): int
    {
        return $this->damageToPlayer;
    }

    /**
     * @return array{leaderIsPlayer: bool, leaderFace: string, leaderCount: int, responderFace: ?string, responderCount: int, damageToPlayer: int, damageToOpponent: int}[]
     */
    public function getExchanges(): array
    {
        return $this->exchanges;
    }

    /**
     * @return array{name: string, iconName: ?string}[]
     */
    public function getDroppedItems(): array
    {
        return $this->droppedItems;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
