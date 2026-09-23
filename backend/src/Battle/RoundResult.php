<?php

namespace App\Battle;

use App\Enum\BitFace;

final class RoundResult
{
    /**
     * @param BitFace[]  $playerFaces   final faces shown on the player's side, after all flips
     * @param BitFace[]  $opponentFaces final faces shown on the opponent's side, after all flips
     * @param Exchange[] $exchanges     per-exchange log
     */
    public function __construct(
        public readonly array $playerFaces,
        public readonly array $opponentFaces,
        public readonly int $damageToOpponent,
        public readonly int $damageToPlayer,
        public readonly array $exchanges = [],
    ) {
    }
}
