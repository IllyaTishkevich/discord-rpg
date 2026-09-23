<?php

namespace App\Battle;

use App\Entity\BattleRound;

/**
 * Result of one interactive lead/respond submission
 * (BattleService::submitExchangeMove()) — either the round is still going
 * (more exchanges to play, possibly waiting on the player again) or it just
 * finished (both sides exhausted their bits, or a knockout cut it short).
 */
final class ExchangeMoveResult
{
    /**
     * @param array{leaderIsPlayer: bool, leaderFace: string, leaderCount: int, responderFace: ?string, responderCount: int, damageToPlayer: int, damageToOpponent: int}[] $newExchanges
     * @param string[]                              $playerFaces       current (post-flip) faces — a bit's face can
     *                                                                  change mid-round via the Flip ability
     * @param string[]                              $opponentFaces
     * @param int[]                                 $playerMultipliers what each currently-shown face is worth (see
     *                                                                  Bit::$multiplierA's docblock) — travels along
     *                                                                  with the face through Flip, same index order
     * @param int[]                                 $opponentMultipliers
     * @param bool[]                                 $playerUsed        which indices have already been activated this
     *                                                                  round and can no longer be selected
     * @param bool[]                                 $opponentUsed
     * @param array{face: string, count: int}|null   $incomingMove
     */
    public function __construct(
        public readonly bool $roundComplete,
        public readonly array $newExchanges,
        public readonly array $playerFaces,
        public readonly array $opponentFaces,
        public readonly array $playerUsed,
        public readonly array $opponentUsed,
        public readonly array $playerMultipliers = [],
        public readonly array $opponentMultipliers = [],
        public readonly ?BattleRound $round = null,
        public readonly ?string $turn = null,
        public readonly ?array $incomingMove = null,
    ) {
    }
}
