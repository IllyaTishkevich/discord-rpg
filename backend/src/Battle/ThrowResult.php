<?php

namespace App\Battle;

final class ThrowResult
{
    /**
     * @param BitThrow[] $playerThrows
     * @param BitThrow[] $opponentThrows
     */
    public function __construct(
        public readonly array $playerThrows,
        public readonly array $opponentThrows,
        public readonly int $playerActionCount,
    ) {
    }
}
