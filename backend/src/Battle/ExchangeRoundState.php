<?php

namespace App\Battle;

use App\Enum\BitFace;

/**
 * Mid-round state for the interactive step-by-step exchange flow
 * (docs/COMBAT_V2_DESIGN.md §7-8) — persisted as JSON on
 * Battle::$pendingExchangeState between HTTP requests, since a round can
 * span several player decisions (lead/respond) each requiring its own
 * request. Cleared once the round fully resolves (see
 * BattleService::submitExchangeMove()).
 */
final class ExchangeRoundState
{
    /**
     * @param BitThrow[]                                                                                                                                                    $playerThrows
     * @param BitThrow[]                                                                                                                                                    $opponentThrows
     * @param bool[]                                                                                                                                                       $playerUsed
     * @param bool[]                                                                                                                                                       $opponentUsed
     * @param array{face: string, count: int}|null                                                                                                                        $pendingLeaderMove set once the leader has moved and we're waiting on the responder
     * @param array{leaderIsPlayer: bool, leaderFace: string, leaderCount: int, responderFace: ?string, responderCount: int, damageToPlayer: int, damageToOpponent: int}[] $exchanges         resolved so far this round
     */
    public function __construct(
        public array $playerThrows,
        public array $opponentThrows,
        public array $playerUsed,
        public array $opponentUsed,
        public bool $leaderIsPlayer,
        public ?array $pendingLeaderMove,
        public bool $playerMirrorActive,
        public bool $opponentMirrorActive,
        public bool $playerAbilityTriggered,
        public bool $opponentAbilityTriggered,
        public array $exchanges,
    ) {
    }

    public function toArray(): array
    {
        return [
            'playerThrows' => array_map(static fn (BitThrow $t) => $t->toArray(), $this->playerThrows),
            'opponentThrows' => array_map(static fn (BitThrow $t) => $t->toArray(), $this->opponentThrows),
            'playerUsed' => $this->playerUsed,
            'opponentUsed' => $this->opponentUsed,
            'leaderIsPlayer' => $this->leaderIsPlayer,
            'pendingLeaderMove' => $this->pendingLeaderMove,
            'playerMirrorActive' => $this->playerMirrorActive,
            'opponentMirrorActive' => $this->opponentMirrorActive,
            'playerAbilityTriggered' => $this->playerAbilityTriggered,
            'opponentAbilityTriggered' => $this->opponentAbilityTriggered,
            'exchanges' => $this->exchanges,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            array_map(BitThrow::fromArray(...), $data['playerThrows']),
            array_map(BitThrow::fromArray(...), $data['opponentThrows']),
            $data['playerUsed'],
            $data['opponentUsed'],
            $data['leaderIsPlayer'],
            $data['pendingLeaderMove'],
            $data['playerMirrorActive'],
            $data['opponentMirrorActive'],
            $data['playerAbilityTriggered'],
            $data['opponentAbilityTriggered'],
            $data['exchanges'],
        );
    }

    public function remainingCount(bool $isPlayerSide): int
    {
        $used = $isPlayerSide ? $this->playerUsed : $this->opponentUsed;

        return \count(array_filter($used, static fn (bool $u) => !$u));
    }

    public function remainingCountByFace(bool $isPlayerSide, BitFace $face): int
    {
        $throws = $isPlayerSide ? $this->playerThrows : $this->opponentThrows;
        $used = $isPlayerSide ? $this->playerUsed : $this->opponentUsed;

        $count = 0;
        foreach ($throws as $i => $throw) {
            if (!$used[$i] && $throw->thrownFace === $face) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * True only once every bit is spent *and* there's no lead move still
     * awaiting a response. The pendingLeaderMove check never changes
     * anything for PvE/event (the bot always resolves a pending lead
     * synchronously before anyone could observe isOver() in between, or
     * only leaves one pending when the responder still has bits left to
     * use, per autoAdvance()'s own remainingCount(true) > 0 guard) — but it
     * matters for PvP, where a lead move can be committed and then sit
     * waiting on a separate request from the real responder, even if that
     * lead move happened to exhaust both sides' bit counts.
     */
    public function isOver(): bool
    {
        return null === $this->pendingLeaderMove
            && 0 === $this->remainingCount(true)
            && 0 === $this->remainingCount(false);
    }
}
