<?php

namespace App\Battle;

use App\Enum\BitFace;

/**
 * One side's activation within a single exchange: a group of same-face
 * bits committed at once (docs/COMBAT_V2_DESIGN.md §3).
 */
final class Move
{
    public function __construct(
        public readonly BitFace $face,
        public readonly int $count,
    ) {
    }
}
