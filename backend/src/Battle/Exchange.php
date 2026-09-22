<?php

namespace App\Battle;

use App\Enum\BitFace;

/**
 * Log entry for one resolved exchange within a round — kept on RoundResult
 * for future history/UI use (docs/COMBAT_V2_DESIGN.md §8); not yet surfaced
 * anywhere.
 */
final class Exchange
{
    public function __construct(
        public readonly bool $leaderIsPlayer,
        public readonly BitFace $leaderFace,
        public readonly int $leaderCount,
        public readonly ?BitFace $responderFace,
        public readonly int $responderCount,
        public readonly int $damageToPlayer,
        public readonly int $damageToOpponent,
    ) {
    }
}
