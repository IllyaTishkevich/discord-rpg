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

    /**
     * Doesn't count an unused-but-Empty-showing bit — it can never be led
     * or responded with, so it must not keep the round "still going" on its
     * own (chooseLeadMove() would find nothing to lead with and throw). If
     * Flip later reveals its other, real face, the next call here picks that
     * up automatically (this re-checks thrownFace fresh every time, nothing
     * to invalidate).
     */
    public function remainingCount(bool $isPlayerSide): int
    {
        $throws = $isPlayerSide ? $this->playerThrows : $this->opponentThrows;
        $used = $isPlayerSide ? $this->playerUsed : $this->opponentUsed;

        $count = 0;
        foreach ($used as $i => $isUsed) {
            if (!$isUsed && BitFace::Empty !== $throws[$i]->thrownFace) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Greedily gathers unused bits of $face in throw order — until running
     * out, or (if $maxAmount is given) until the combined multiplier
     * reaches or exceeds it. Always takes at least one whole bit if any are
     * available and $maxAmount hasn't already been reached, even if that
     * bit alone overshoots $maxAmount — bits can't be partially activated,
     * and overshooting (more defense than the incoming attack needs) is
     * harmless, unlike stopping short.
     *
     * @return array{count: int, amount: int} count = number of bit objects
     *         (for marking them used), amount = sum of their multipliers
     *         (for damage/blocking/action-points/ability-cost)
     */
    public function gatherByFace(bool $isPlayerSide, BitFace $face, ?int $maxAmount = null): array
    {
        $throws = $isPlayerSide ? $this->playerThrows : $this->opponentThrows;
        $used = $isPlayerSide ? $this->playerUsed : $this->opponentUsed;

        $count = 0;
        $amount = 0;
        foreach ($throws as $i => $throw) {
            if (null !== $maxAmount && $amount >= $maxAmount) {
                break;
            }
            if (!$used[$i] && $throw->thrownFace === $face) {
                ++$count;
                $amount += $throw->thrownMultiplier;
            }
        }

        return ['count' => $count, 'amount' => $amount];
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
