<?php

namespace App\Battle;

final class ThrowResult
{
    /**
     * @param BitThrow[]                    $playerThrows
     * @param BitThrow[]                    $opponentThrows
     * @param 'lead'|'respond'|null         $turn          PvE/event only — whose move it is to start the
     *                                                     interactive exchange sequence (null for PvP, which
     *                                                     doesn't use it — see docs/COMBAT_V2_DESIGN.md §7)
     * @param array{face: string, count: int}|null $incomingMove set when $turn === 'respond': what the bot already played
     * @param bool[]|null                    $playerUsed    which bit indices are already spent — almost always
     *                                                      all-false right after a fresh throw, but startRound()
     *                                                      can auto-play in rare edge cases (see its docblock)
     * @param bool[]|null                    $opponentUsed
     */
    public function __construct(
        public readonly array $playerThrows,
        public readonly array $opponentThrows,
        public readonly int $playerActionCount,
        public readonly ?string $turn = null,
        public readonly ?array $incomingMove = null,
        public readonly ?array $playerUsed = null,
        public readonly ?array $opponentUsed = null,
    ) {
    }
}
